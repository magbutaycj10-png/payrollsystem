<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'leave-requests';
$db  = getDB();
$msg = null;

/*
 * Approve / reject happens from the details dialog, so the decision is
 * always made with the full reason in view rather than from a truncated
 * table cell.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['leave_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = trim($_POST['review_note'] ?? '');

    if ($id && in_array($action, ['approve', 'reject'], true)) {
        /* also recomputes the open payroll it touches — see decideLeave() */
        $msg = decideLeave($db, $id, $action, $note, null);
    }
}

$filter = $_GET['filter'] ?? 'All';
if (!in_array($filter, ['All','Pending','Approved','Rejected'])) $filter = 'All';

$whereStatus = $filter !== 'All' ? " WHERE lr.status = ? " : '';
$params      = $filter !== 'All' ? [$filter] : [];

$st = $db->prepare("
    SELECT lr.*, e.branch, e.position, e.rest_days
    FROM leave_requests lr
    LEFT JOIN employees e ON e.emp_id = lr.emp_id
    $whereStatus
    ORDER BY lr.created_at DESC
");
$st->execute($params);
$requests = $st->fetchAll();

$counts = $db->query("
    SELECT status, COUNT(*) AS cnt FROM leave_requests GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

/* Everything the dialog needs, keyed by request id */
$detailMap = [];
foreach ($requests as $lr) {
    $days = (int)((strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400) + 1;
    $detailMap[$lr['id']] = [
        'emp'        => $lr['emp_name'],
        'emp_id'     => $lr['emp_id'],
        'branch'     => $lr['branch'] ?: '—',
        'position'   => $lr['position'] ?: '—',
        'type'       => $lr['leave_type'],
        'from'       => date('M d, Y', strtotime($lr['date_from'])),
        'to'         => date('M d, Y', strtotime($lr['date_to'])),
        'days'       => $days,
        'days_label' => leaveDaysLabel($lr['date_from'], $lr['date_to'], $lr['rest_days'] ?? null),
        'reason'     => trim((string)$lr['reason']) !== '' ? $lr['reason'] : 'No reason given.',
        'status'     => $lr['status'],
        'reviewer'   => $lr['reviewed_by'] ?: '—',
        'note'       => trim((string)$lr['review_note']) !== '' ? $lr['review_note'] : '—',
        'submitted'  => date('M d, Y g:i A', strtotime($lr['created_at'])),
        'reviewed'   => $lr['reviewed_at'] ? date('M d, Y g:i A', strtotime($lr['reviewed_at'])) : '—',
    ];
}

$companyName = getSetting('company_name', 'My Company');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Requests — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/employee.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            .sidebar   { display: none !important; }
            .main-content { padding: 0; margin: 0; }
            body { background: #fff; }
        }
        .print-header { display: none; }
        @media print { .print-header { display: block; margin-bottom: 16px; } }

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
        .det-decide {
            border-top: 1px solid var(--border); padding-top: 16px;
        }
        .det-actions { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .det-actions .btn { flex: 1; min-width: 130px; justify-content: center; text-align: center; }
        @media (max-width: 560px) { .det-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header no-print">
        <div>
            <h1>Leave Requests</h1>
            <p>All employee leave and time-off requests</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="leave-history.php" class="btn btn-ghost">Leave History</a>
            <button class="btn btn-print" onclick="window.open('print-doc.php?doc=leaves','_blank','width=980,height=760')">Print All</button>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] === 'success' ? 'success' : 'warn' ?> no-print">
            <?= htmlspecialchars($msg['text']) ?>
        </div>
    <?php endif; ?>

    <!-- Print header -->
    <div class="print-header">
        <h2><?= htmlspecialchars($companyName) ?> — Leave Request Report</h2>
        <p>Printed: <?= date('F j, Y g:i A') ?> &nbsp;&nbsp; Filter: <?= $filter ?></p>
    </div>

    <!-- Stats -->
    <div class="card-grid no-print" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-bottom:20px;">
        <div class="card card-yellow">
            <div class="card-label">Pending</div>
            <div class="card-value"><?= $counts['Pending'] ?? 0 ?></div>
        </div>
        <div class="card card-green">
            <div class="card-label">Approved</div>
            <div class="card-value"><?= $counts['Approved'] ?? 0 ?></div>
        </div>
        <div class="card card-red">
            <div class="card-label">Rejected</div>
            <div class="card-value"><?= $counts['Rejected'] ?? 0 ?></div>
        </div>
        <div class="card card-accent">
            <div class="card-label">Total</div>
            <div class="card-value"><?= array_sum($counts) ?></div>
        </div>
    </div>

    <div class="toolbar no-print">
        <?php foreach (['All','Pending','Approved','Rejected'] as $f): ?>
        <a href="?filter=<?= $f ?>" class="btn <?= $filter === $f ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= $f ?></a>
        <?php endforeach; ?>
    </div>

    <div class="box">
        <div class="box-header no-print">
            <h2><?= $filter !== 'All' ? $filter : 'All' ?> Leave Requests (<?= count($requests) ?>)</h2>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee</th><th>Branch</th><th>Type</th><th>From</th><th>To</th>
                        <th>Days</th><th>Status</th><th>Reviewed By</th><th>Submitted</th><th class="no-print">Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:30px;">No <?= strtolower($filter) ?> leave requests.</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $lr): ?>
                    <?php $days = (int)((strtotime($lr['date_to']) - strtotime($lr['date_from'])) / 86400) + 1; ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($lr['emp_name']) ?></strong>
                            <br><small style="color:#9ca3af;"><?= htmlspecialchars($lr['emp_id']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($lr['branch'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($lr['leave_type']) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_from'])) ?></td>
                        <td><?= date('M d, Y', strtotime($lr['date_to'])) ?></td>
                        <td><?= htmlspecialchars(leaveDaysLabel($lr['date_from'], $lr['date_to'], $lr['rest_days'] ?? null)) ?></td>
                        <td>
                            <span class="badge badge-<?= $lr['status']==='Approved' ? 'green' : ($lr['status']==='Rejected' ? 'red' : 'yellow') ?>">
                                <?= $lr['status'] ?>
                            </span>
                        </td>
                        <td style="font-size:.85rem;"><?= htmlspecialchars($lr['reviewed_by'] ?: '—') ?></td>
                        <td style="font-size:.8rem;color:#6b7280;"><?= date('M d, Y', strtotime($lr['created_at'])) ?></td>
                        <td class="no-print">
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

<!-- Details dialog: the reason lives here, not in the table -->
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

        <div class="det-grid">
            <div class="det-cell"><span>Reviewed by</span><b id="lmReviewer"></b></div>
            <div class="det-cell"><span>Reviewed on</span><b id="lmReviewed"></b></div>
            <div class="det-cell" style="grid-column:1/-1;"><span>Reviewer note</span><b id="lmNote"></b></div>
        </div>

        <!-- Decision form, shown only while the request is still pending -->
        <div class="det-decide" id="lmDecide" style="display:none;">
            <div class="det-section">Decision</div>
            <form method="POST" id="lmForm">
                <input type="hidden" name="leave_id" id="lmId">
                <input type="hidden" name="action"   id="lmAction">
                <input type="text" name="review_note" class="form-control" id="lmReviewNote"
                       placeholder="Note for the employee (optional for approve, recommended for reject)">
                <div class="det-actions">
                    <button type="button" class="btn btn-green" onclick="decide('approve')">Approve</button>
                    <button type="button" class="btn btn-red"   onclick="decide('reject')">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var LEAVES = <?= json_encode($detailMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var modal  = document.getElementById('leaveModal');

function openLeave(id) {
    var d = LEAVES[id];
    if (!d) return;

    document.getElementById('lmTitle').textContent    = d.emp;
    document.getElementById('lmSub').textContent      = d.type + ' — submitted ' + d.submitted;
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

    /* Only a pending request can still be decided */
    document.getElementById('lmDecide').style.display = d.status === 'Pending' ? 'block' : 'none';
    document.getElementById('lmId').value             = id;
    document.getElementById('lmReviewNote').value     = '';

    modal.classList.add('open');
}

function closeLeave() { modal.classList.remove('open'); }

function decide(action) {
    if (action === 'reject' && !confirm('Reject this leave request?')) return;
    document.getElementById('lmAction').value = action;
    document.getElementById('lmForm').submit();
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLeave();
});
</script>
</body>
</html>
