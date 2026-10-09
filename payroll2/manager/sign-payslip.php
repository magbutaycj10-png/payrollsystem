<?php
require_once __DIR__ . '/includes/auth.php';
requireManager();

$activePage = 'sign-payslip';
$db  = getDB();
$m   = mgr();

[$scopeWhere, $scopeParams] = mgrScopeWhere('p');

// Handle signature save (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['payroll_id'], $data['signature_data'])) {
        $pr = $db->prepare("SELECT p.* FROM payroll p
                            JOIN employees e ON e.emp_id = p.emp_id
                            WHERE p.id = ? $scopeWhere");
        $pr->execute(array_merge([(int)$data['payroll_id']], $scopeParams));
        $pr = $pr->fetch();

        if ($pr) {
            [$ok, $message] = savePayslipSignature($db, $pr, (string)($data['period_label'] ?? ''), (string)$data['signature_data']);
            jsonResponse(['ok' => $ok, 'message' => $message], $ok ? 200 : 409);
        }
        jsonResponse(['ok' => false, 'message' => 'Payroll record not found.'], 404);
    }
    jsonResponse(['ok' => false, 'message' => 'Invalid request.'], 400);
}

$periods   = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();
$period_id  = (int)($_GET['period']    ?? defaultPeriodId($db, $periods));
$payroll_id = (int)($_GET['payroll_id'] ?? 0);

$curPeriod = null;
foreach ($periods as $p) { if ($p['id'] == $period_id) { $curPeriod = $p; break; } }

$rows = [];
if ($period_id) {
    $params = array_merge([$period_id], $scopeParams);
    $st = $db->prepare("
        SELECT p.*, ps.id AS sig_id, ps.signed_at
        FROM payroll p
        JOIN employees e ON e.emp_id = p.emp_id
        /* only a signature for the current net pay counts (see signatureCurrent) */
        LEFT JOIN payslip_signatures ps ON ps.payroll_id = p.id
             AND (ps.net_signed IS NULL OR ABS(ps.net_signed - p.net_pay) < 0.005)
        WHERE p.period_id = ? $scopeWhere
        ORDER BY p.emp_name ASC
    ");
    $st->execute($params);
    $rows = $st->fetchAll();
}

$selected = null;
if ($payroll_id) {
    foreach ($rows as $r) { if ($r['id'] == $payroll_id) { $selected = $r; break; } }
}

$signedCount   = count(array_filter($rows, fn($r) => $r['sig_id']));
$unsignedCount = count($rows) - $signedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Payslip - Manager Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <style>
        /* Marks pay that was corrected after the period had been finalized */
        .rev-tag {
            display: inline-block; padding: 1px 6px; border-radius: 999px;
            background: #fef3c7; color: #92400e; font-size: .64rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .04em; vertical-align: middle;
        }
        .sig-outer  { border: 2px solid var(--border); border-radius: 10px; background: #fff; touch-action: none; position: relative; }
        .sig-canvas { display: block; width: 100%; border-radius: 8px; cursor: crosshair; }
        .sig-hint   { text-align: center; font-size: .8rem; color: #9ca3af; margin-top: 6px; }
        .ps-grid    { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap: 10px; margin-bottom: 18px; }
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
            <h1>Sign Payslip</h1>
            <p>Employee signs to confirm receipt of salary<?= ' - ' . htmlspecialchars(mgrScopeLabel()) ?></p>
        </div>
    </div>

    <!-- Period + employee picker -->
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

                <?php if (!empty($rows)): ?>
                <label style="font-weight:600;font-size:.875rem;">Employee:</label>
                <select name="payroll_id" class="p-form-control" style="max-width:240px;" onchange="this.form.submit()">
                    <option value="">- Select Employee -</option>
                    <?php foreach ($rows as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $r['id'] == $payroll_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['emp_name']) ?> (<?= $r['emp_id'] ?>)
                        <?= $r['sig_id'] ? ' - signed' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:.82rem;color:#6b7280;"><?= $signedCount ?> signed &nbsp;&nbsp; <?= $unsignedCount ?> unsigned</span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($selected): ?>
    <!-- Payslip summary + signature pad -->
    <div class="p-box" style="margin-bottom:16px;">
        <div class="p-box-header">
            <h2>Payslip - <?= htmlspecialchars($selected['emp_name']) ?></h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span class="badge badge-<?= $selected['status']==='Finalized' ? 'green' : 'blue' ?>"><?= $selected['status'] ?></span>
                <?php if (!empty($selected['revised_after_finalize'])): ?>
                    <span class="rev-tag" title="Corrected after this period was finalized">Revised</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-box-body">
            <?php if (!empty($selected['revised_after_finalize'])): ?>
            <!-- A signature collected against superseded figures is worthless,
                 so warn the manager before the pad is used. -->
            <div class="p-alert p-alert-warn">
                These figures were <strong>corrected</strong> after
                <?= htmlspecialchars($curPeriod['period_label']) ?> had been finalized<?php
                ?><?= !empty($selected['revised_at']) ? ' on ' . date('M d, Y g:i A', strtotime($selected['revised_at'])) : '' ?>.
                If this employee already signed an earlier copy, have them sign this one again.
            </div>
            <?php endif; ?>

            <div class="ps-grid">
                <div class="ps-item"><div class="ps-label">Employee ID</div><div class="ps-value"><?= htmlspecialchars($selected['emp_id']) ?></div></div>
                <div class="ps-item"><div class="ps-label">Period</div><div class="ps-value" style="font-size:.82rem;"><?= htmlspecialchars($curPeriod['period_label']) ?></div></div>
                <div class="ps-item"><div class="ps-label">Hours</div><div class="ps-value"><?= number_format($selected['hours_worked'], 1) ?></div></div>
                <div class="ps-item"><div class="ps-label">Gross Pay</div><div class="ps-value">₱<?= number_format($selected['gross_pay'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">SSS</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['sss'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">PhilHealth</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['philhealth'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">Pag-IBIG</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['pagibig'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label"><?= (float)$selected['withholding_tax'] < 0 ? 'Tax refund' : 'Tax' ?></div><div class="ps-value" style="color:<?= (float)$selected['withholding_tax'] < 0 ? '#16a34a' : '#dc2626' ?>;"><?= (float)$selected['withholding_tax'] < 0 ? '+' : '-' ?>₱<?= number_format(abs((float)$selected['withholding_tax']), 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">Bonus</div><div class="ps-value" style="color:#16a34a;">+₱<?= number_format($selected['bonus'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">Other Deductions</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['other_deductions'], 2) ?></div></div>
                <div class="ps-item">
                    <div class="ps-label">Net Pay</div>
                    <div class="ps-value" style="color:#166534;font-size:1.2rem;">₱<?= number_format($selected['net_pay'], 2) ?></div>
                </div>
            </div>

            <?php if ($selected['sig_id']): ?>
            <div class="p-alert p-alert-success" style="margin-bottom:16px;">
                Already signed on <?= date('M d, Y g:i A', strtotime($selected['signed_at'])) ?>.
                You can re-sign below to replace it.
            </div>
            <?php endif; ?>

            <?php $canSign = ($curPeriod['status'] ?? '') !== 'Open'; ?>
            <?php if (!$canSign): ?>
            <div class="p-alert p-alert-warn" style="margin-bottom:16px;">
                <?= htmlspecialchars($curPeriod['period_label']) ?> is still open - its figures can still change.
                Ask the admin to finalize it before collecting signatures.
            </div>
            <?php endif; ?>

            <!-- Signature pad -->
            <div style="max-width:560px;">
                <label style="font-weight:700;font-size:.9rem;display:block;margin-bottom:8px;">
                    Employee Signature <span style="font-weight:400;color:#6b7280;">(sign in the box below)</span>
                </label>
                <div class="sig-outer">
                    <canvas id="sigCanvas" class="sig-canvas" height="200"></canvas>
                </div>
                <p class="sig-hint">Draw signature using finger or mouse</p>
                <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                    <button class="btn btn-primary" id="saveBtn" onclick="saveSignature()" <?= $canSign ? '' : 'disabled title="The period is not finalized yet"' ?>>Save Signature</button>
                    <button class="btn btn-ghost"   onclick="clearCanvas()">Clear</button>
                </div>
                <div id="sigMsg" style="margin-top:10px;font-size:.875rem;display:none;"></div>
            </div>
        </div>
    </div>
    <?php elseif (!empty($rows)): ?>
        <div class="p-alert p-alert-info">Select an employee from the dropdown above to collect their signature.</div>
    <?php elseif ($period_id): ?>
        <div class="p-alert p-alert-warn">No payroll records for this period<?= ' for the employees you manage' ?>.</div>
    <?php endif; ?>

    <!-- All employees for this period -->
    <?php if (!empty($rows)): ?>
    <div class="p-box">
        <div class="p-box-header">
            <h2>All Employees This Period</h2>
            <span style="font-size:.82rem;color:#6b7280;"><?= count($rows) ?> employee(s)</span>
        </div>
        <div class="p-table-wrap">
            <table class="p-table">
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Net Pay</th><th>Signature</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['emp_id']) ?></td>
                    <td><?= htmlspecialchars($r['emp_name']) ?></td>
                    <td><strong>₱<?= number_format($r['net_pay'], 2) ?></strong></td>
                    <td>
                        <?php if ($r['sig_id']): ?>
                            <span class="badge badge-green">Signed <?= date('M d', strtotime($r['signed_at'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-yellow">Unsigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?period=<?= $period_id ?>&payroll_id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">
                            <?= $r['sig_id'] ? 'Re-sign' : 'Get Signature' ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const canvas = document.getElementById('sigCanvas');
    if (!canvas) return;

    const ctx   = canvas.getContext('2d');
    let drawing = false, hasDrawn = false;

    function resize() {
        const rect  = canvas.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        canvas.width  = rect.width  * ratio;
        canvas.height = 200 * ratio;
        ctx.scale(ratio, ratio);
        ctx.strokeStyle = '#111827';
        ctx.lineWidth   = 2.5;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
    }

    function pos(e) {
        const rect = canvas.getBoundingClientRect();
        const src  = e.touches ? e.touches[0] : e;
        return { x: src.clientX - rect.left, y: src.clientY - rect.top };
    }

    function start(e) { e.preventDefault(); drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
    function move(e)  { e.preventDefault(); if (!drawing) return; hasDrawn = true; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
    function stop(e)  { e.preventDefault(); drawing = false; }

    canvas.addEventListener('mousedown',  start);
    canvas.addEventListener('mousemove',  move);
    canvas.addEventListener('mouseup',    stop);
    canvas.addEventListener('mouseleave', stop);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove',  move,  { passive: false });
    canvas.addEventListener('touchend',   stop);

    resize();
    window.addEventListener('resize', resize);

    window.clearCanvas = function () {
        const rect = canvas.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, 200);
        hasDrawn = false;
    };

    window.saveSignature = function () {
        if (!hasDrawn) { showMsg('Please draw the employee\'s signature first.', false); return; }

        const btn = document.getElementById('saveBtn');
        btn.disabled    = true;
        btn.textContent = 'Saving…';

        fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                payroll_id:     <?= (int)$payroll_id ?>,
                signature_data: canvas.toDataURL('image/png'),
                period_label:   <?= json_encode($curPeriod['period_label'] ?? '') ?>,
            }),
        })
        .then(r => r.json())
        .then(res => {
            showMsg(res.message, res.ok);
            if (res.ok) {
                btn.textContent = 'Saved!';
                setTimeout(() => location.reload(), 1200);
            } else {
                btn.disabled    = false;
                btn.textContent = 'Save Signature';
            }
        })
        .catch(() => {
            showMsg('Network error. Please try again.', false);
            btn.disabled    = false;
            btn.textContent = 'Save Signature';
        });
    };

    function showMsg(text, ok) {
        const el = document.getElementById('sigMsg');
        el.style.display      = 'block';
        el.style.color        = ok ? '#166534' : '#991b1b';
        el.style.background   = ok ? '#dcfce7'  : '#fee2e2';
        el.style.padding      = '10px 14px';
        el.style.borderRadius = '8px';
        el.textContent        = text;
    }
})();
</script>
</body>
</html>
