<?php
require_once __DIR__ . '/includes/auth.php';
requireManager();

$activePage = 'sign-adjustment';
$db  = getDB();
$m   = mgr();

[$scopeWhere, $scopeParams] = mgrScopeWhere('e');

// Handle signature save (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['adjustment_id'], $data['signature_data'])) {
        $adj = $db->prepare("SELECT h.id, h.period_id, h.amount FROM bonus_deductions h
                             JOIN employees e ON e.id = h.employee_id
                             WHERE h.id = ? $scopeWhere");
        $adj->execute(array_merge([(int)$data['adjustment_id']], $scopeParams));
        $adj = $adj->fetch();

        if ($adj) {
            [$ok, $message] = saveAdjustmentSignature($db, $adj, (string)$data['signature_data']);
            jsonResponse(['ok' => $ok, 'message' => $message], $ok ? 200 : 409);
        }
        jsonResponse(['ok' => false, 'message' => 'Bonus / deduction entry not found.'], 404);
    }
    jsonResponse(['ok' => false, 'message' => 'Invalid request.'], 400);
}

$periods   = $db->query("SELECT * FROM payroll_periods ORDER BY start_date DESC")->fetchAll();
$period_id = (int)($_GET['period'] ?? defaultPeriodId($db, $periods));
$adj_id    = (int)($_GET['adj'] ?? 0);

$curPeriod = null;
foreach ($periods as $p) { if ($p['id'] == $period_id) { $curPeriod = $p; break; } }

$rows = [];
if ($period_id) {
    $st = $db->prepare("
        SELECT " . ADJ_LINE_COLUMNS . ", s.id AS sig_id, s.signed_at
          FROM bonus_deductions h " . ADJ_LINE_JOINS . "
        /* only a signature for the amount as it stands counts */
     LEFT JOIN adjustment_signatures s ON s.adjustment_id = h.id
           AND (s.amount_signed IS NULL OR ABS(s.amount_signed - h.amount) < 0.005)
         WHERE h.period_id = ? $scopeWhere ORDER BY e.full_name ASC, h.id ASC
    ");
    $st->execute(array_merge([$period_id], $scopeParams));
    $rows = $st->fetchAll();
}

$selected = null;
if ($adj_id) {
    foreach ($rows as $r) { if ($r['id'] == $adj_id) { $selected = $r; break; } }
}
$signedCount = count(array_filter($rows, fn($r) => $r['sig_id']));
$canSign     = ($curPeriod['status'] ?? '') !== 'Open';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Bonus Receipt - Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <style>
        .rev-tag { display:inline-block; padding:1px 6px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:.64rem;
                   font-weight:700; text-transform:uppercase; letter-spacing:.04em; vertical-align:middle; }
        .sig-outer  { border: 2px solid var(--border); border-radius: 10px; background: #fff; touch-action: none; position: relative; }
        .sig-canvas { display: block; width: 100%; border-radius: 8px; cursor: crosshair; }
        .sig-hint   { text-align: center; font-size: .8rem; color: #9ca3af; margin-top: 6px; }
        .ps-grid    { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px; margin-bottom: 18px; }
        .ps-item    { background: #f9fafb; border: 1px solid var(--border); border-radius: 8px; padding: 12px; }
        .ps-label   { font-size: .72rem; font-weight: 600; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .ps-value   { font-size: 1rem; font-weight: 700; }
        @media (max-width: 480px) { .ps-grid { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>Sign Bonus Receipt</h1>
            <p>Employee signs the bonus / deduction receipt<?= ' - ' . htmlspecialchars(mgrScopeLabel()) ?></p>
        </div>
    </div>

    <!-- Period picker -->
    <div class="p-box" style="margin-bottom:16px;">
        <div class="p-box-body" style="padding:12px 16px;">
            <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-weight:600;font-size:.875rem;">Period:</label>
                <select name="period" class="p-form-control" style="max-width:220px;" onchange="this.form.submit()">
                    <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['period_label']) ?> · <?= htmlspecialchars(periodTypeLabel($p)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($rows): ?>
                <span style="font-size:.82rem;color:#6b7280;"><?= $signedCount ?> signed &nbsp;&nbsp; <?= count($rows) - $signedCount ?> unsigned</span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($selected): ?>
    <div class="p-box" style="margin-bottom:16px;">
        <div class="p-box-header">
            <h2><?= htmlspecialchars($selected['entry_type']) ?> - <?= htmlspecialchars($selected['emp_name']) ?></h2>
            <span class="badge badge-<?= $selected['entry_type'] === 'Bonus' ? 'green' : 'red' ?>"><?= htmlspecialchars($selected['entry_type']) ?></span>
        </div>
        <div class="p-box-body">
            <div class="ps-grid">
                <div class="ps-item"><div class="ps-label">Employee ID</div><div class="ps-value"><?= htmlspecialchars($selected['emp_id']) ?></div></div>
                <div class="ps-item"><div class="ps-label">Period</div><div class="ps-value" style="font-size:.82rem;"><?= htmlspecialchars($curPeriod['period_label']) ?></div></div>
                <div class="ps-item"><div class="ps-label">Reason</div><div class="ps-value" style="font-size:.9rem;"><?= htmlspecialchars($selected['reason']) ?></div></div>
                <div class="ps-item"><div class="ps-label">Amount</div><div class="ps-value" style="color:<?= $selected['entry_type'] === 'Bonus' ? '#166534' : '#991b1b' ?>;font-size:1.2rem;">₱<?= number_format($selected['amount'], 2) ?></div></div>
            </div>

            <?php if ($selected['sig_id']): ?>
            <div class="p-alert p-alert-success" style="margin-bottom:16px;">
                Already signed on <?= date('M d, Y g:i A', strtotime($selected['signed_at'])) ?>.
                You can re-sign below to replace it.
            </div>
            <?php endif; ?>

            <?php if (!$canSign): ?>
            <div class="p-alert p-alert-warn" style="margin-bottom:16px;">
                <?= htmlspecialchars($curPeriod['period_label']) ?> is still open - the entry can still be corrected.
                Ask the admin to finalize it before collecting signatures.
            </div>
            <?php endif; ?>

            <div style="max-width:560px;">
                <label style="font-weight:700;font-size:.9rem;display:block;margin-bottom:8px;">
                    Employee Signature <span style="font-weight:400;color:#6b7280;">(sign in the box below)</span>
                </label>
                <div class="sig-outer"><canvas id="sigCanvas" class="sig-canvas" height="200"></canvas></div>
                <p class="sig-hint">Draw signature using finger or mouse</p>
                <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                    <button class="btn btn-primary" id="saveBtn" onclick="saveSignature()" <?= $canSign ? '' : 'disabled title="The period is not finalized yet"' ?>>Save Signature</button>
                    <button class="btn btn-ghost" onclick="pad.clear()">Clear</button>
                </div>
                <div id="sigMsg" style="margin-top:10px;font-size:.875rem;display:none;"></div>
            </div>
        </div>
    </div>
    <?php elseif ($rows): ?>
        <div class="p-alert p-alert-info">Choose an entry below and press <b>Get Signature</b>.</div>
    <?php elseif ($period_id): ?>
        <div class="p-alert p-alert-warn">No bonus or deduction was recorded in this period<?= ' for the employees you manage' ?>.</div>
    <?php endif; ?>

    <?php if ($rows): ?>
    <div class="p-box">
        <div class="p-box-header">
            <h2>Bonuses &amp; Deductions This Period</h2>
            <span style="font-size:.82rem;color:#6b7280;"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
        </div>
        <div class="p-table-wrap">
            <table class="p-table">
                <thead>
                    <tr><th>ID</th><th>Employee</th><th>Type</th><th>Reason</th><th>Amount</th><th>Signature</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['emp_id']) ?></td>
                    <td><?= htmlspecialchars($r['emp_name']) ?></td>
                    <td style="white-space:nowrap;">
                        <span class="badge badge-<?= $r['entry_type'] === 'Bonus' ? 'green' : 'red' ?>"><?= htmlspecialchars($r['entry_type']) ?></span>
                        <?php if ((int)$r['finalize_cycle'] > 0): ?><span class="rev-tag" title="Recorded after the period had been finalized - a correction">Revision</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($r['reason']) ?></td>
                    <td><strong>₱<?= number_format($r['amount'], 2) ?></strong></td>
                    <td>
                        <?php if ($r['sig_id']): ?>
                            <span class="badge badge-green">Signed <?= date('M d', strtotime($r['signed_at'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-yellow">Unsigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?period=<?= $period_id ?>&adj=<?= $r['id'] ?>" class="btn btn-ghost btn-sm"><?= $r['sig_id'] ? 'Re-sign' : 'Get Signature' ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="/assets/js/sig-pad.js"></script>
<script>
const canvas = document.getElementById('sigCanvas');
const pad    = canvas ? SigPad(canvas) : null;

function showMsg(text, ok) {
    const el = document.getElementById('sigMsg');
    el.style.display = 'block'; el.style.color = ok ? '#166534' : '#991b1b'; el.style.background = ok ? '#dcfce7' : '#fee2e2';
    el.style.padding = '10px 14px'; el.style.borderRadius = '8px'; el.textContent = text;
}

function saveSignature() {
    if (!pad.hasInk()) { showMsg('Please draw the employee\'s signature first.', false); return; }
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ adjustment_id: <?= (int)$adj_id ?>, signature_data: pad.toDataURL() }),
    })
    .then(r => r.json())
    .then(res => {
        showMsg(res.message, res.ok);
        if (res.ok) { btn.textContent = 'Saved!'; setTimeout(() => location.reload(), 1200); }
        else        { btn.disabled = false; btn.textContent = 'Save Signature'; }
    })
    .catch(() => { showMsg('Network error. Please try again.', false); btn.disabled = false; btn.textContent = 'Save Signature'; });
}
</script>
</body>
</html>
