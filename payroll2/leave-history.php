<?php
require 'includes/helpers.php';
requireAuth();

/*
 * Leave history - the searchable record of every request ever filed.
 * The working queue lives on leave-requests.php; this page is for looking
 * things up after the fact: by employee, by date range, by outcome.
 */

$activePage = 'leave-history';
$db = getDB();

$q        = trim($_GET['q']      ?? '');
$dateFrom = trim($_GET['from']   ?? '');
$dateTo   = trim($_GET['to']     ?? '');
$status   = $_GET['status']      ?? 'All';
$type     = $_GET['type']        ?? 'All';

$statuses = ['All', 'Pending', 'Approved', 'Rejected'];
$types    = ['All', 'Vacation', 'Sick Leave', 'Emergency', 'Other'];
if (!in_array($status, $statuses, true)) $status = 'All';
if (!in_array($type,   $types,    true)) $type   = 'All';

/* Dates arrive from <input type="date"> as YYYY-MM-DD; ignore anything else */
$isDate   = fn($d) => $d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$dateFrom = $isDate($dateFrom) ? $dateFrom : '';
$dateTo   = $isDate($dateTo)   ? $dateTo   : '';

$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = "(lr.emp_name LIKE ? OR lr.emp_id LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
/* A request matches the range when its leave dates overlap it */
if ($dateFrom !== '') { $where[] = "lr.date_to   >= ?"; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = "lr.date_from <= ?"; $params[] = $dateTo; }
if ($status !== 'All') { $where[] = "lr.status = ?";     $params[] = $status; }
if ($type   !== 'All') { $where[] = "lr.leave_type = ?"; $params[] = $type; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $db->prepare("
    SELECT lr.*, e.branch, e.position, e.rest_days
    FROM leave_requests lr
    LEFT JOIN employees e ON e.emp_id = lr.emp_id
    $whereSql
    ORDER BY lr.date_from DESC, lr.id DESC
    LIMIT 500
");
$st->execute($params);
$rows = $st->fetchAll();

/* Totals for the result set currently on screen */
$sumDays = 0;
$byStatus = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
$detailMap = [];
foreach ($rows as $lr) {
    $days = (int)((strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400) + 1;
    $sumDays += leaveDays($lr['date_from'], $lr['date_to'], $lr['rest_days'] ?? null)['duty'];
    if (isset($byStatus[$lr['status']])) $byStatus[$lr['status']]++;

    $detailMap[$lr['id']] = [
        'emp'       => $lr['emp_name'],
        'emp_id'    => $lr['emp_id'],
        'branch'    => $lr['branch']   ?: '-',
        'position'  => $lr['position'] ?: '-',
        'type'      => $lr['leave_type'],
        'from'      => date('M d, Y', strtotime($lr['date_from'])),
        'to'        => date('M d, Y', strtotime($lr['date_to'])),
        'days'      => $days,
        'days_label' => leaveDaysLabel($lr['date_from'], $lr['date_to'], $lr['rest_days'] ?? null),
        'reason'    => trim((string)$lr['reason']) !== '' ? $lr['reason'] : 'No reason given.',
        'status'    => $lr['status'],
        'reviewer'  => $lr['reviewed_by'] ?: '-',
        'note'      => trim((string)$lr['review_note']) !== '' ? $lr['review_note'] : '-',
        'submitted' => date('M d, Y g:i A', strtotime($lr['created_at'])),
        'reviewed'  => $lr['reviewed_at'] ? date('M d, Y g:i A', strtotime($lr['reviewed_at'])) : '-',
    ];
}
$hasFilters = $q !== '' || $dateFrom !== '' || $dateTo !== '' || $status !== 'All' || $type !== 'All';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave History - Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/employee.css">
    <style>
        /* ── Filter bar ── */
        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 18px;
            box-shadow: var(--shadow-sm); margin-bottom: 20px;
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
        .filter-search input { min-width: 260px; }
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
        @media (max-width: 720px) {
            .filter-search input { min-width: 0; width: 100%; }
            .filter-field, .filter-actions { width: 100%; }
            .filter-actions { margin-left: 0; }
        }
        @media (max-width: 560px) { .det-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Leave History</h1>
            <p>Search every leave request by employee, date range or outcome</p>
        </div>
        <a href="leave-requests.php" class="btn btn-primary">Review Requests</a>
    </div>

    <!-- Search + filters -->
    <form method="GET" class="filter-bar">
        <div class="filter-field filter-search">
            <label>Employee name or ID</label>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="e.g. Maria Santos or EMP-001">
        </div>
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
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($hasFilters): ?>
                <a href="leave-history.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Summary of the current result set -->
    <div class="card-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-bottom:20px;">
        <div class="card card-accent">
            <div class="card-label">Requests found</div>
            <div class="card-value"><?= count($rows) ?></div>
            <div class="card-sub"><?= $hasFilters ? 'Matching your filters' : 'All time' ?></div>
        </div>
        <div class="card card-yellow">
            <div class="card-label">Pending</div>
            <div class="card-value"><?= $byStatus['Pending'] ?></div>
        </div>
        <div class="card card-green">
            <div class="card-label">Approved</div>
            <div class="card-value"><?= $byStatus['Approved'] ?></div>
        </div>
        <div class="card card-red">
            <div class="card-label">Rejected</div>
            <div class="card-value"><?= $byStatus['Rejected'] ?></div>
        </div>
        <div class="card card-purple">
            <div class="card-label" title="Calendar days less each employee's days off - what payroll counts">Leave duty days</div>
            <div class="card-value"><?= $sumDays ?></div>
            <div class="card-sub">Across these requests</div>
        </div>
    </div>

    <div class="box">
        <div class="box-header">
            <h2>Results</h2>
            <?php if (count($rows) >= 500): ?>
                <span class="badge badge-yellow">Showing the first 500 - narrow the search</span>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee</th><th>Branch</th><th>Type</th><th>From</th><th>To</th>
                        <th>Days</th><th>Status</th><th>Reviewed By</th><th>Submitted</th><th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:30px;">
                        <?= $hasFilters ? 'No leave requests match these filters.' : 'No leave requests on record yet.' ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $lr): ?>
                    <?php $days = (int)((strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400) + 1; ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($lr['emp_name']) ?></strong>
                            <br><small style="color:#9ca3af;"><?= htmlspecialchars($lr['emp_id']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($lr['branch'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($lr['leave_type']) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_from'])) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_to'])) ?></td>
                        <td><?= htmlspecialchars(leaveDaysLabel($lr['date_from'], $lr['date_to'], $lr['rest_days'] ?? null)) ?></td>
                        <td>
                            <span class="badge badge-<?= $lr['status']==='Approved' ? 'green' : ($lr['status']==='Rejected' ? 'red' : 'yellow') ?>">
                                <?= $lr['status'] ?>
                            </span>
                        </td>
                        <td style="font-size:.85rem;"><?= htmlspecialchars($lr['reviewed_by'] ?: '-') ?></td>
                        <td style="font-size:.8rem;color:#6b7280;"><?= date('M d, Y', strtotime($lr['created_at'])) ?></td>
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

<!-- Read-only details: decisions are made on the Leave Requests page -->
<div id="leaveModal" class="modal-overlay" onclick="if(event.target===this)closeLeave()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeLeave()">Close</button>
        <h2 class="modal-title" id="lmTitle">Leave Request</h2>
        <p style="font-size:.82rem;color:#6b7280;margin-bottom:18px;" id="lmSub"></p>

        <div class="det-grid">
            <div class="det-cell"><span>Employee ID</span><b id="lmEmpId"></b></div>
            <div class="det-cell"><span>Branch</span><b id="lmBranch"></b></div>
            <div class="det-cell"><span>Position</span><b id="lmPosition"></b></div>
            <div class="det-cell"><span>Leave type</span><b id="lmType"></b></div>
            <div class="det-cell"><span>From</span><b id="lmFrom"></b></div>
            <div class="det-cell"><span>To</span><b id="lmTo"></b></div>
            <div class="det-cell"><span>Duration</span><b id="lmDays"></b></div>
            <div class="det-cell"><span>Status</span><b id="lmStatus"></b></div>
        </div>

        <div class="det-section">Reason given</div>
        <div class="det-reason" id="lmReason"></div>

        <div class="det-grid" style="margin-bottom:0;">
            <div class="det-cell"><span>Reviewed by</span><b id="lmReviewer"></b></div>
            <div class="det-cell"><span>Reviewed on</span><b id="lmReviewed"></b></div>
            <div class="det-cell" style="grid-column:1/-1;"><span>Reviewer note</span><b id="lmNote"></b></div>
        </div>

        <p id="lmPendingHint" style="display:none;font-size:.8rem;color:#92400e;background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:10px 12px;margin-top:16px;">
            Still pending - approve or reject it from
            <a href="leave-requests.php?filter=Pending" style="color:inherit;font-weight:700;">Leave Requests</a>.
        </p>
    </div>
</div>

<script>
var LEAVES = <?= json_encode($detailMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var modal  = document.getElementById('leaveModal');

function openLeave(id) {
    var d = LEAVES[id];
    if (!d) return;

    document.getElementById('lmTitle').textContent    = d.emp;
    document.getElementById('lmSub').textContent      = d.type + ' - submitted ' + d.submitted;
    document.getElementById('lmEmpId').textContent    = d.emp_id;
    document.getElementById('lmBranch').textContent   = d.branch;
    document.getElementById('lmPosition').textContent = d.position;
    document.getElementById('lmType').textContent     = d.type;
    document.getElementById('lmFrom').textContent     = d.from;
    document.getElementById('lmTo').textContent       = d.to;
    document.getElementById('lmDays').textContent     = d.days_label;   /* calendar days, and the duty days among them (what payroll pays) */
    document.getElementById('lmReason').textContent   = d.reason;
    document.getElementById('lmReviewer').textContent = d.reviewer;
    document.getElementById('lmReviewed').textContent = d.reviewed;
    document.getElementById('lmNote').textContent     = d.note;

    var cls = d.status === 'Approved' ? 'badge-green' : (d.status === 'Rejected' ? 'badge-red' : 'badge-yellow');
    document.getElementById('lmStatus').innerHTML = '<span class="badge ' + cls + '">' + d.status + '</span>';
    document.getElementById('lmPendingHint').style.display = d.status === 'Pending' ? 'block' : 'none';

    modal.classList.add('open');
}

function closeLeave() { modal.classList.remove('open'); }

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLeave();
});
</script>
</body>
</html>
