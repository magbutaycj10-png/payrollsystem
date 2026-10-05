<?php
require 'includes/helpers.php';
requireAuth();

$activePage = 'sign-payslip';
$db  = getDB();
$msg = null;

// Handle signature save (POST with JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['payroll_id'], $data['signature_data'])) {
        $pr = $db->prepare("SELECT * FROM payroll WHERE id=?");
        $pr->execute([(int)$data['payroll_id']]);
        $pr = $pr->fetch();

        if ($pr) {
            [$ok, $message] = savePayslipSignature($db, $pr, (string)($data['period_label'] ?? ''), (string)$data['signature_data']);
            jsonResponse(['ok' => $ok, 'message' => $message], $ok ? 200 : 409);
        }
        jsonResponse(['ok' => false, 'message' => 'Payroll record not found.'], 404);
    }
    jsonResponse(['ok' => false, 'message' => 'Invalid request.'], 400);
}

$periods  = $db->query("SELECT * FROM payroll_periods ORDER BY period_start DESC")->fetchAll();
$period_id = (int)($_GET['period'] ?? defaultPeriodId($db, $periods));
$payroll_id = (int)($_GET['payroll_id'] ?? 0);

$curPeriod = null;
foreach ($periods as $p) { if ($p['id'] == $period_id) { $curPeriod = $p; break; } }

$rows = [];
if ($period_id) {
    $st = $db->prepare("
        SELECT p.*, ps.id AS sig_id, ps.signed_at
        FROM payroll p
        /* only a signature for the current net pay counts (see signatureCurrent) */
        LEFT JOIN payslip_signatures ps ON ps.payroll_id = p.id
             AND (ps.net_signed IS NULL OR ABS(ps.net_signed - p.net_pay) < 0.005)
        WHERE p.period_id = ? ORDER BY p.emp_name ASC
    ");
    $st->execute([$period_id]);
    $rows = $st->fetchAll();
}

$selected = null;
if ($payroll_id) {
    foreach ($rows as $r) { if ($r['id'] == $payroll_id) { $selected = $r; break; } }
}

$companyName = getSetting('company_name', 'My Company');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Payslip — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Marks pay that was corrected after the period had been finalized */
        .rev-tag {
            display: inline-block; margin-left: 4px; padding: 1px 6px;
            border-radius: 999px; background: #fef3c7; color: #92400e;
            font-size: .64rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; vertical-align: middle; white-space: nowrap;
        }
        tr.is-revised td { background: #fffbeb; }
        .sig-outer  { border: 2px solid #e5e7eb; border-radius: 10px; background: #fff; touch-action: none; position: relative; }
        .sig-canvas { display: block; width: 100%; border-radius: 8px; cursor: crosshair; }
        .sig-hint   { text-align: center; font-size: .8rem; color: #9ca3af; margin-top: 6px; }
        .payslip-summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap: 10px; margin-bottom: 18px; }
        .ps-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
        .ps-label { font-size: .72rem; font-weight: 600; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .ps-value { font-size: 1rem; font-weight: 700; }
        @media (max-width: 600px) { .payslip-summary { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Sign Payslip</h1>
            <p>Employee draws their signature, then the acknowledgement receipt is issued in two copies</p>
        </div>
        <?php if (!empty($rows)): ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-print" onclick="openReceipt({doc:'payslip',period:<?= $period_id ?>,copies:'both',sig:0})">
                Blank Copies
            </button>
            <button class="btn btn-print" onclick="openReceipt({doc:'payslip',period:<?= $period_id ?>,copies:'both'})">
                Print All
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <!-- Period + employee picker -->
    <div class="box" style="margin-bottom:20px;">
        <div class="box-body" style="padding:14px 20px;">
            <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-weight:600;font-size:.875rem;">Period:</label>
                <select name="period" class="form-control" style="min-width:200px;" onchange="this.form.submit()">
                    <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['period_label']) ?> · <?= htmlspecialchars(periodTypeLabel($p)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <?php if (!empty($rows)): ?>
                <label style="font-weight:600;font-size:.875rem;">Employee:</label>
                <select name="payroll_id" class="form-control" style="min-width:200px;" onchange="this.form.submit()">
                    <option value="">— Select Employee —</option>
                    <?php foreach ($rows as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $r['id'] == $payroll_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['emp_name']) ?> (<?= $r['emp_id'] ?>)
                        <?= $r['sig_id'] ? ' - Signed' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($selected): ?>
    <!-- Payslip summary -->
    <div class="box" style="margin-bottom:20px;">
        <div class="box-header">
            <h2>Payslip for <?= htmlspecialchars($selected['emp_name']) ?></h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span class="badge badge-<?= $selected['status']==='Finalized' ? 'green' : 'blue' ?>"><?= $selected['status'] ?></span>
                <?php if (!empty($selected['revised_after_finalize'])): ?>
                    <span class="rev-tag" title="Corrected after this period was finalized">Revised</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="box-body">
            <?php if (!empty($selected['revised_after_finalize'])): ?>
            <!-- A signature collected against superseded figures is worthless,
                 so warn before the pad is used. -->
            <div class="alert alert-warn" style="margin-bottom:16px;">
                <span>
                    These figures were <strong>corrected</strong> after
                    <?= htmlspecialchars($curPeriod['period_label']) ?> had been finalized<?php
                    ?><?= !empty($selected['revised_at']) ? ' on ' . date('M d, Y g:i A', strtotime($selected['revised_at'])) : '' ?>.
                    Reissue the payslip and collect the signature again if an earlier copy was already signed.
                </span>
                <a href="history.php?emp_id=<?= urlencode($selected['emp_id']) ?>&period_id=<?= $period_id ?>">What changed</a>
            </div>
            <?php endif; ?>

            <div class="payslip-summary">
                <div class="ps-item"><div class="ps-label">Employee ID</div><div class="ps-value"><?= htmlspecialchars($selected['emp_id']) ?></div></div>
                <div class="ps-item"><div class="ps-label">Period</div><div class="ps-value" style="font-size:.85rem;"><?= htmlspecialchars($curPeriod['period_label']) ?></div></div>
                <div class="ps-item"><div class="ps-label">Hours</div><div class="ps-value"><?= number_format($selected['hours_worked'], 1) ?></div></div>
                <div class="ps-item"><div class="ps-label">Gross Pay</div><div class="ps-value">₱<?= number_format($selected['gross_pay'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">SSS</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['sss'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">PhilHealth</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['philhealth'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">Pag-IBIG</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['pagibig'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">Tax</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['withholding_tax'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">Bonus</div><div class="ps-value" style="color:#16a34a;">+₱<?= number_format($selected['bonus'], 2) ?></div></div>
                <div class="ps-item"><div class="ps-label">Other Deductions</div><div class="ps-value" style="color:#dc2626;">-₱<?= number_format($selected['other_deductions'], 2) ?></div></div>
                <div class="ps-item" style="border-top:2px solid #22c55e;"><div class="ps-label">Net Pay</div><div class="ps-value" style="color:#166534;font-size:1.2rem;">₱<?= number_format($selected['net_pay'], 2) ?></div></div>
            </div>

            <?php if ($selected['sig_id']): ?>
            <div class="alert alert-success" style="margin-bottom:16px;">
                Already signed on <?= date('M d, Y g:i A', strtotime($selected['signed_at'])) ?>.
                You can re-sign below to replace the existing signature.
            </div>
            <?php endif; ?>

            <?php $canSign = ($curPeriod['status'] ?? '') !== 'Open'; ?>
            <?php if (!$canSign): ?>
            <div class="alert alert-warn" style="margin-bottom:16px;">
                <span><?= htmlspecialchars($curPeriod['period_label']) ?> is still open — its figures can still change.
                Finalize it in Payroll Processing before collecting signatures.</span>
                <a href="payroll.php?period=<?= $period_id ?>">Payroll Processing</a>
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
                <p class="sig-hint">Draw your signature above using a finger or mouse</p>
                <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                    <button class="btn btn-primary" id="saveBtn" onclick="saveSignature()" <?= $canSign ? '' : 'disabled title="Finalize the period first"' ?>>Save Signature</button>
                    <button class="btn btn-ghost"   onclick="clearCanvas()">Clear</button>
                    <button class="btn btn-print"
                            onclick="openReceipt({doc:'payslip',payroll_id:<?= (int)$payroll_id ?>,copies:'both'})">
                        Print Receipt &mdash; Employee + Company Copy
                    </button>
                </div>
                <div id="sigMsg" style="margin-top:10px;font-size:.875rem;display:none;"></div>
            </div>
        </div>
    </div>
    <?php elseif (!empty($rows)): ?>
        <div class="alert alert-warn">Select an employee from the dropdown above to begin signing.</div>
    <?php elseif ($period_id): ?>
        <div class="alert alert-warn">No payroll records for this period.</div>
    <?php endif; ?>

    <!-- All employees list for this period -->
    <?php if (!empty($rows)): ?>
    <div class="box">
        <div class="box-header"><h2>All Employees This Period</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Net Pay</th><th>Signature</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                <tr class="<?= !empty($r['revised_after_finalize']) ? 'is-revised' : '' ?>">
                    <td><?= htmlspecialchars($r['emp_id']) ?></td>
                    <td>
                        <?= htmlspecialchars($r['emp_name']) ?>
                        <?php if (!empty($r['revised_after_finalize'])): ?>
                            <span class="rev-tag" title="Corrected after this period was finalized">Revised</span>
                        <?php endif; ?>
                    </td>
                    <td><strong>₱<?= number_format($r['net_pay'], 2) ?></strong></td>
                    <td>
                        <?php if ($r['sig_id']): ?>
                            <span class="badge badge-green">Signed <?= date('M d', strtotime($r['signed_at'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-yellow">Unsigned</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="?period=<?= $period_id ?>&payroll_id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">
                            <?= $r['sig_id'] ? 'Re-sign' : 'Get Signature' ?>
                        </a>
                        <button class="btn btn-print btn-sm"
                                onclick="openReceipt({doc:'payslip',payroll_id:<?= (int)$r['id'] ?>,copies:'both'})">
                            Print Receipt
                        </button>
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
/* Opens the shared document renderer (print-doc.php) so the
   receipt issued here is identical to the one issued from Reports. */
function openReceipt(params) {
    window.open('print-doc.php?' + new URLSearchParams(params).toString(),
                '_blank', 'width=980,height=760');
}

(function() {
    const canvas  = document.getElementById('sigCanvas');
    if (!canvas) return;

    const ctx     = canvas.getContext('2d');
    let drawing   = false;
    let hasDrawn  = false;

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

    window.clearCanvas = function() {
        const rect = canvas.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, 200);
        hasDrawn = false;
    };

    window.saveSignature = function() {
        if (!hasDrawn) { showMsg('Please draw your signature first.', false); return; }

        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.textContent = 'Saving…';

        const data = canvas.toDataURL('image/png');

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                payroll_id:     <?= (int)$payroll_id ?>,
                signature_data: data,
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
                btn.disabled = false;
                btn.textContent = 'Save Signature';
            }
        })
        .catch(() => {
            showMsg('Network error. Please try again.', false);
            btn.disabled = false;
            btn.textContent = 'Save Signature';
        });
    };

    function showMsg(text, ok) {
        const el = document.getElementById('sigMsg');
        el.style.display = 'block';
        el.style.color   = ok ? '#166534' : '#991b1b';
        el.style.background = ok ? '#dcfce7' : '#fee2e2';
        el.style.padding = '10px 14px';
        el.style.borderRadius = '8px';
        el.textContent = text;
    }
})();
</script>
</body>
</html>
