<?php
require_once __DIR__ . '/includes/auth.php';
requireEmployee();

$activePage = 'payslips';
$db  = getDB();
$e   = emp();

$companyName = getSetting('company_name', 'My Company');

$rows = $db->prepare("
    SELECT " . PAY_LINE_COLUMNS . ", pp.period_label, pp.start_date, pp.end_date
    FROM payroll p " . PAY_LINE_JOINS . "
    JOIN payroll_periods pp ON pp.id = p.period_id
    WHERE e.employee_code = ?
    ORDER BY pp.start_date DESC, pp.id DESC
");
$rows->execute([$e['id']]);
$rows = $rows->fetchAll();

/* Signatures in one query (the database is a round trip away). A signature
   given for a different net pay than the current one - the payslip was
   corrected after signing - no longer counts. */
$signatures = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $sig = $db->prepare("SELECT payroll_id, signature_data, signed_at, net_signed FROM payslip_signatures
                          WHERE payroll_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $sig->execute($ids);
    $netOf = array_column($rows, 'net_pay', 'id');
    foreach ($sig->fetchAll() as $sg) {
        if (signatureCurrent($sg['net_signed'], $netOf[$sg['payroll_id']] ?? null)) $signatures[$sg['payroll_id']] = $sg;
    }
}

/*
 * The reason behind every bonus and deduction on each payslip, so the employee
 * can see WHY a net pay is what it is instead of only the total: the admin's
 * entries (Adjustment Details), absent days, late / undertime - see
 * payLineReasons(). finalize_cycle > 0 marks an entry recorded after the
 * period was closed.
 */
$reasons = payLineReasons($db, $rows);

/* One line of the "why" list: what it is, where it came from, and the pesos */
function reasonRow(string $label, string $detail, string $sign, float $amount, string $color, string $note = '', bool $revision = false): string {
    $money = $sign === '' ? '' : $sign . '₱' . number_format($amount, 2);
    return '<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:7px 10px;border:1px solid var(--border);'
         . 'border-radius:8px;margin-bottom:6px;background:' . ($revision ? '#fffbeb' : '#f9fafb') . ';">'
         . '<span style="font-size:.82rem;"><strong style="color:' . $color . ';">' . htmlspecialchars($label) . '</strong>'
         . ($detail !== '' ? '&nbsp;-&nbsp;' . htmlspecialchars($detail) : '')
         . ($revision ? ' <span class="rev-tag">Revision</span>' : '')
         . ($note !== '' ? '<br><small style="color:#9ca3af;">' . htmlspecialchars($note) . '</small>' : '')
         . '</span>'
         . '<span style="font-size:.9rem;font-weight:700;color:' . $color . ';">' . $money . '</span></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payslips - Employee Portal</title>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <style>
        .payslip-card {
            background: #fff; border: 1px solid var(--border); border-radius: var(--radius);
            padding: 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);
        }
        .payslip-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .payslip-grid   { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px; margin-bottom: 14px; }
        .payslip-item   { padding: 10px; background: #f9fafb; border-radius: 8px; border: 1px solid var(--border); }
        .payslip-item-label { font-size: .72rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
        .payslip-item-value { font-size: 1rem; font-weight: 700; }
        .sig-thumbnail  { max-width: 180px; border: 1px solid var(--border); border-radius: 6px; margin-top: 8px; }
        /* Marks pay that was corrected after the period had been finalized */
        .rev-tag {
            display: inline-block; margin-left: 4px; padding: 1px 6px;
            border-radius: 999px; background: #fef3c7; color: #92400e;
            font-size: .64rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; vertical-align: middle; white-space: nowrap;
        }
        .why-head { font-size: .74rem; color: var(--text-muted); margin: 9px 0 5px; }
        @media print {
            .no-print { display: none !important; }
            .payslip-card { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="p-content">
    <div class="p-header">
        <div>
            <h1>My Payslips</h1>
            <p>Complete history of your pay records and signed receipts</p>
        </div>
        <button class="btn btn-print no-print" onclick="openReceipt({})">Print All</button>
    </div>

    <?php if (empty($rows)): ?>
        <div class="p-alert p-alert-info">No payslips available yet. Check back after the admin processes payroll.</div>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
        <?php $sig = $signatures[$r['id']] ?? null; ?>
        <?php
        $why      = $reasons[$r['id']] ?? ['bonus' => [], 'deduction' => [], 'attendance' => [], 'unlisted' => ['Bonus' => 0, 'Deduction' => 0]];
        $govt     = (float)$r['withholding_tax'] + (float)$r['sss'] + (float)$r['philhealth'] + (float)$r['pagibig'];
        $hasBonus = $why['bonus'] || $why['unlisted']['Bonus'] > 0;
        $hasDed   = $why['deduction'] || $why['unlisted']['Deduction'] > 0 || abs($govt) >= 0.005;
        $hasAtt   = (bool)$why['attendance'];
        ?>
        <div class="payslip-card">
            <div class="payslip-header">
                <div>
                    <strong style="font-size:1.05rem;"><?= htmlspecialchars($r['period_label']) ?></strong>
                    <br><small style="color:#6b7280;">
                        <?php [$covFrom, $covTo] = payLineRange($r); ?>
                        <?= date('M d', strtotime($covFrom)) ?> – <?= date('M d, Y', strtotime($covTo)) ?><?= $covFrom !== $r['start_date'] ? ' (whole month)' : '' ?>
                    </small>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <span class="badge badge-<?= $r['status']==='Finalized' ? 'green' : 'blue' ?>"><?= $r['status'] ?></span>
                    <?php if (!empty($r['revised_after_finalize'])): ?>
                        <span class="rev-tag" title="Corrected after this period was finalized">Revised</span>
                    <?php endif; ?>
                    <?php if ($sig): ?>
                        <span class="badge badge-purple">Signed <?= date('M d, Y', strtotime($sig['signed_at'])) ?></span>
                    <?php endif; ?>
                    <button class="btn btn-ghost btn-sm no-print" onclick="toggleDetail(<?= $r['id'] ?>)">Details</button>
                    <button class="btn btn-print btn-sm no-print" onclick="openReceipt({payroll_id:<?= (int)$r['id'] ?>})">Print</button>
                </div>
            </div>

            <!-- Summary row always visible -->
            <div class="payslip-grid">
                <div class="payslip-item">
                    <div class="payslip-item-label">Hours</div>
                    <div class="payslip-item-value"><?= number_format($r['hours_worked'], 1) ?> hrs</div>
                </div>
                <div class="payslip-item">
                    <div class="payslip-item-label">Gross Pay</div>
                    <div class="payslip-item-value">₱<?= number_format($r['gross_pay'], 2) ?></div>
                </div>
                <div class="payslip-item">
                    <div class="payslip-item-label">Bonus</div>
                    <div class="payslip-item-value" style="color:#16a34a;">₱<?= number_format($r['bonus'], 2) ?></div>
                </div>
                <?php $allDed = $govt + (float)$r['other_deductions']; ?>
                <div class="payslip-item" title="Tax, SSS, PhilHealth, Pag-IBIG and other deductions - see Details">
                    <div class="payslip-item-label">Deductions</div>
                    <div class="payslip-item-value" style="color:#dc2626;">₱<?= number_format($allDed, 2) ?></div>
                </div>
                <div class="payslip-item">
                    <div class="payslip-item-label">Net Pay</div>
                    <div class="payslip-item-value" style="color:#166534;font-size:1.2rem;">₱<?= number_format($r['net_pay'], 2) ?></div>
                </div>
            </div>

            <?php if (!empty($r['revised_after_finalize'])): ?>
                <!-- The employee is told directly that this period was corrected
                     after it was closed, and which entries did it. -->
                <div class="p-alert p-alert-warn" style="margin-bottom:12px;">
                    This payslip was <strong>revised</strong> after
                    <?= htmlspecialchars($r['period_label']) ?> had been finalized<?php
                    ?><?= !empty($r['revised_at']) ? ' on ' . date('M d, Y', strtotime($r['revised_at'])) : '' ?>.
                    The figures above are the corrected ones and replace any earlier copy.
                    <?php if (!empty($why['bonus']) || !empty($why['deduction'])): ?>
                        See the list below for what changed.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hasBonus || $hasDed || $hasAtt): ?>
                <!-- The reason behind every bonus and deduction in the figures
                     above: the admin's entries (bonus_deductions), absent days,
                     late / undertime. -->
                <div style="margin-bottom:12px;">
                    <div class="payslip-item-label" style="margin-bottom:2px;">Why your pay is what it is</div>

                    <?php if ($hasBonus): ?>
                        <div class="why-head">Bonuses - added to your pay</div>
                        <?php foreach ($why['bonus'] as $a): ?>
                            <?= reasonRow('Bonus', (string)$a['reason'], '+', (float)$a['amount'], '#16a34a', date('M d, Y', strtotime($a['entry_date'])), (int)$a['finalize_cycle'] > 0) ?>
                        <?php endforeach; ?>
                        <?php if ($why['unlisted']['Bonus'] > 0): ?>
                            <?= reasonRow('Bonus', 'no reason was recorded for this amount - ask your admin', '+', $why['unlisted']['Bonus'], '#16a34a') ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($hasDed): ?>
                        <div class="why-head">Deductions - taken from your pay (the Deductions amount above)</div>
                        <?php if (abs($govt) >= 0.005): ?>
                            <?= reasonRow('Tax & contributions', 'withholding tax, SSS, PhilHealth and Pag-IBIG - each amount is under Details', $govt >= 0 ? '−' : '+', abs($govt), '#6b7280') ?>
                        <?php endif; ?>
                        <?php foreach ($why['deduction'] as $a): ?>
                            <?= reasonRow('Deduction', (string)$a['reason'], '−', (float)$a['amount'], '#dc2626', date('M d, Y', strtotime($a['entry_date'])), (int)$a['finalize_cycle'] > 0) ?>
                        <?php endforeach; ?>
                        <?php if ($why['unlisted']['Deduction'] > 0): ?>
                            <?= reasonRow('Deduction', 'no reason was recorded for this amount - ask your admin', '−', $why['unlisted']['Deduction'], '#dc2626') ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($hasAtt): ?>
                        <div class="why-head">Attendance - already taken out of your Basic Pay, so it is not in the Deductions amount above</div>
                        <?php foreach ($why['attendance'] as $a): ?>
                            <?= reasonRow($a['label'], $a['detail'], $a['amount'] > 0 ? '−' : '', (float)$a['amount'], '#dc2626') ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Expanded detail (toggle) -->
            <div id="detail-<?= $r['id'] ?>" style="display:none;">
                <div class="payslip-grid" style="margin-bottom:10px;">
                    <div class="payslip-item">
                        <div class="payslip-item-label">Overtime</div>
                        <div class="payslip-item-value"><?= number_format($r['overtime_hours'], 1) ?> hrs</div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label">Late</div>
                        <div class="payslip-item-value"><?= number_format($r['late_hours'], 1) ?> hrs</div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label">Undertime</div>
                        <div class="payslip-item-value"><?= number_format((float)($r['undertime_hours'] ?? 0), 1) ?> hrs<?php
                            if ((float)($r['undertime_deduction'] ?? 0) > 0): ?> <small style="color:#dc2626;">(−₱<?= number_format($r['undertime_deduction'], 2) ?>)</small><?php endif; ?></div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label">Absent / Leave / Off</div>
                        <div class="payslip-item-value"><?= (float)($r['absent_days'] ?? 0) + 0 ?> / <?= (float)($r['leave_days'] ?? 0) + 0 ?> / <?= (float)($r['days_off'] ?? 0) + 0 ?> days<?php
                            if ((float)($r['absent_deduction'] ?? 0) > 0): ?> <small style="color:#dc2626;">(−₱<?= number_format($r['absent_deduction'], 2) ?>)</small><?php endif; ?></div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label"><?= (float)$r['withholding_tax'] < 0 ? 'Tax Refunded' : 'Tax Withheld' ?></div>
                        <div class="payslip-item-value" style="color:<?= (float)$r['withholding_tax'] < 0 ? '#16a34a' : '#dc2626' ?>;"><?= pesoFmt(abs((float)$r['withholding_tax'])) ?></div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label">SSS</div>
                        <div class="payslip-item-value" style="color:#dc2626;">₱<?= number_format($r['sss'], 2) ?></div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label">PhilHealth</div>
                        <div class="payslip-item-value" style="color:#dc2626;">₱<?= number_format($r['philhealth'], 2) ?></div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label">Pag-IBIG</div>
                        <div class="payslip-item-value" style="color:#dc2626;">₱<?= number_format($r['pagibig'], 2) ?></div>
                    </div>
                    <div class="payslip-item">
                        <div class="payslip-item-label">Other Deductions</div>
                        <div class="payslip-item-value" style="color:#dc2626;">₱<?= number_format($r['other_deductions'], 2) ?></div>
                    </div>
                </div>

                <?php if ($sig): ?>
                <div style="margin-top:10px;">
                    <p style="font-size:.8rem;color:#6b7280;margin-bottom:6px;">Your signature on receipt (<?= date('M d, Y g:i A', strtotime($sig['signed_at'])) ?>):</p>
                    <img src="<?= htmlspecialchars($sig['signature_data']) ?>" alt="Signature" class="sig-thumbnail">
                </div>
                <?php endif; ?>

                <p style="font-size:.78rem;color:#9ca3af;margin-top:10px;">
                    Processed <?= date('M d, Y', strtotime($r['created_at'])) ?>
                </p>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
/* Employees print through employee/print-doc.php, which serves the very
   same document template as the admin side but only ever for the
   payslips belonging to the signed-in employee. */
function openReceipt(params) {
    window.open('print-doc.php?' + new URLSearchParams(params).toString(),
                '_blank', 'width=980,height=760');
}

function toggleDetail(id) {
    const el = document.getElementById('detail-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
</body>
</html>
