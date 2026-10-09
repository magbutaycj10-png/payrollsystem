<?php
/* =============================================================
 * includes/bir-print.php
 * -------------------------------------------------------------
 * ONE shared renderer for every printed document in the system.
 *
 * Every print in the app (individual payslip, batch "print all",
 * the signed acknowledgement receipt, the payroll summary and the
 * audit reports) is produced from the helpers in this file, so all
 * of them share the same L&N Pharmacy letterhead and footer.
 *
 * These are internal payroll documents, not registered receipts.
 * The system is a payroll tool: it is not accredited by, or
 * registered with, any tax authority, so nothing printed here
 * claims a Permit To Use, an Authority To Print, or an accredited
 * printer. The company details below are plain letterhead fields
 * read from the settings table and edited in Settings > Company
 * & Document Details.
 * ============================================================= */

require_once __DIR__ . '/helpers.php';

/* -------------------------------------------------------------
 * birConfig() - every company letterhead field with a safe
 * default. Cached for the request.
 * ----------------------------------------------------------- */
function birConfig(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $defaults = [
        'bir_registered_name'  => 'L & N PHARMACY',
        'bir_trade_name'       => 'L & N Pharmacy',
        'bir_business_style'   => 'Retail Pharmacy',
        'bir_address'          => '',
        'bir_tin'              => '',

        // Name of this system, printed in the document footer
        'bir_system_name'       => 'L&N Automated Payroll System v2.0',

        // Signatory printed on every acknowledgement receipt
        'bir_signatory_name'     => '',
        'bir_signatory_position' => 'HR / Payroll Officer',
    ];

    $cfg = [];
    foreach ($defaults as $k => $v) {
        $val = getSetting($k, $v);
        $cfg[$k] = ($val === '') ? $v : $val;
    }

    // company_name stays the master brand name used across the app
    $cfg['company_name'] = getSetting('company_name', 'L&N Pharmacy');
    return $cfg;
}

/* -------------------------------------------------------------
 * Automatic document numbering.
 *
 * A document number has to be traceable, so the series is continuous and
 * never repeats. Nothing here is configurable and the count never
 * restarts: one unbroken, ever-rising series per document type that runs
 * straight through every month and every payroll run.
 *
 * A number is allocated the first time a document is issued and is then
 * bound to that record permanently, so reprinting a payslip reproduces
 * the same receipt number rather than consuming the next one.
 * ----------------------------------------------------------- */
function birSerialFor(string $series, int $refId): int {
    $db = getDB();

    $find = $db->prepare('SELECT serial_no FROM document_serials WHERE series = ? AND ref_id = ?');
    $find->execute([$series, $refId]);
    $existing = $find->fetchColumn();
    if ($existing !== false) return (int)$existing;

    try {
        $db->prepare('INSERT IGNORE INTO document_series (series, next_no) VALUES (?, 1)')->execute([$series]);

        /* LAST_INSERT_ID(next_no) returns the value from BEFORE the bump and
           the bump is atomic, so two people printing at once can never be
           handed the same number. */
        $db->prepare('UPDATE document_series SET next_no = LAST_INSERT_ID(next_no) + 1 WHERE series = ?')
           ->execute([$series]);
        $no = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();

        $db->prepare('INSERT INTO document_serials (series, ref_id, serial_no) VALUES (?,?,?)')
           ->execute([$series, $refId, $no]);
        return $no;

    } catch (PDOException $e) {
        /* Someone issued this exact document first - adopt their number. */
        $find->execute([$series, $refId]);
        $existing = $find->fetchColumn();
        if ($existing !== false) return (int)$existing;
        throw $e;
    }
}

/* Where an automatic series currently stands. Read-only - shown in
 * Settings so the numbering is visible without being editable. */
function birSeriesStatus(string $series): array {
    $db = getDB();

    $st = $db->prepare('SELECT next_no FROM document_series WHERE series = ?');
    $st->execute([$series]);
    $next = (int)($st->fetchColumn() ?: 1);

    $st = $db->prepare('SELECT COUNT(*) FROM document_serials WHERE series = ?');
    $st->execute([$series]);

    return ['next' => $next, 'issued' => (int)$st->fetchColumn()];
}

/* The number the next document in this series will carry. */
function birNextDocNo(string $series): string {
    return $series . '-' . str_pad((string)birSeriesStatus($series)['next'], 6, '0', STR_PAD_LEFT);
}

/* Payslip / acknowledgement receipt: AR-000001, AR-000002, … */
function birDocNo(int $payrollId): string {
    return 'AR-' . str_pad((string)birSerialFor('AR', $payrollId), 6, '0', STR_PAD_LEFT);
}

/* -------------------------------------------------------------
 * amountInWords() - 12345.67 -> "TWELVE THOUSAND THREE HUNDRED
 * FORTY-FIVE PESOS AND 67/100".  A receipt is not valid without
 * the amount spelled out.
 * ----------------------------------------------------------- */
function amountInWords(float $amount): string {
    $pesos    = (int)floor(abs($amount));
    $centavos = (int)round((abs($amount) - $pesos) * 100);
    if ($centavos === 100) { $centavos = 0; $pesos++; }

    $words = strtoupper(_numToWords($pesos));
    $sign  = $amount < 0 ? 'MINUS ' : '';
    /* "ONE PESO", but "ZERO PESOS" and "TWO PESOS" */
    return $sign . $words . ($pesos === 1 ? ' PESO' : ' PESOS') . ' AND ' . str_pad((string)$centavos, 2, '0', STR_PAD_LEFT) . '/100';
}

function _numToWords(int $n): string {
    static $ones = ['zero','one','two','three','four','five','six','seven','eight','nine','ten',
                    'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen',
                    'eighteen','nineteen'];
    static $tens = [2=>'twenty',3=>'thirty',4=>'forty',5=>'fifty',6=>'sixty',7=>'seventy',
                    8=>'eighty',9=>'ninety'];

    if ($n < 20)  return $ones[$n];
    if ($n < 100) {
        $r = $n % 10;
        return $tens[intdiv($n, 10)] . ($r ? '-' . $ones[$r] : '');
    }
    if ($n < 1000) {
        $r = $n % 100;
        return $ones[intdiv($n, 100)] . ' hundred' . ($r ? ' ' . _numToWords($r) : '');
    }
    foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $unit => $name) {
        if ($n >= $unit) {
            $r = $n % $unit;
            return _numToWords(intdiv($n, $unit)) . ' ' . $name . ($r ? ' ' . _numToWords($r) : '');
        }
    }
    return (string)$n;
}

/* Small formatting helpers used by the templates below. */
function birPeso($n): string { $n = (float)$n; return ($n < 0 ? '&minus;' : '') . '&#8369;' . number_format(abs($n), 2); }
function birEsc($s): string  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =============================================================
 *  SHARED STYLESHEET - every printed document uses this exact CSS
 * ============================================================= */
function birPrintCss(): string {
    return <<<'CSS'
:root{
  --ink:#111827; --muted:#6b7280; --line:#c9ced6;
  --navy:#1a3a8f;      /* L&N wordmark blue  */
  --green:#4aa314;     /* PHARMACY green     */
  --red:#d81f26;       /* mortar red         */
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{background:#f1f3f7;}
body{
  font-family:'Segoe UI',Arial,Helvetica,sans-serif;
  color:var(--ink); font-size:8.7pt; line-height:1.28;
  -webkit-print-color-adjust:exact; print-color-adjust:exact;
}
.sheet{
  width:210mm; min-height:297mm; margin:10px auto; padding:9mm 10mm;
  background:#fff; box-shadow:0 2px 14px rgba(0,0,0,.16);
}
.sheet + .sheet{ page-break-before:always; }

/* -- one document copy ------------------------------------- */
.copy{ border:1.4px solid var(--navy); border-radius:3px; padding:3mm 4.2mm; position:relative; overflow:hidden; }
.cut{ border-top:1px dashed #9aa1ad; text-align:center; height:0; margin:2.2mm 0; }
.cut span{
  position:relative; top:-6px; background:#fff; padding:0 8px;
  font-size:7pt; letter-spacing:.16em; color:#9aa1ad; text-transform:uppercase;
}
/* Faint logo watermark behind each copy - deters photocopy reuse. */
.copy::after{
  content:''; position:absolute; inset:0;
  background:url('%LOGO%') center / 34% no-repeat;
  opacity:.04; pointer-events:none; z-index:0;
}
.copy > *{ position:relative; z-index:1; }
.copy.report{ overflow:visible; }

/* -- letterhead -------------------------------------------- */
.lh{ display:flex; gap:4mm; align-items:flex-start; }
.lh-logo{ width:12mm; height:12mm; object-fit:contain; flex:none; }
.lh-org{ flex:1; min-width:0; }
.lh-name{ font-size:11.6pt; font-weight:800; letter-spacing:.02em; color:var(--navy); line-height:1.05; }
.lh-name em{ font-style:normal; color:var(--green); }
.lh-sub{ font-size:6.6pt; color:var(--muted); }
.lh-reg{ font-size:6.6pt; }
.lh-reg b{ font-weight:700; }
.lh-reg .blank{ border-bottom:1px solid #9aa1ad; }
.lh-meta{ text-align:right; flex:none; font-size:6.6pt; min-width:40mm; }
.lh-meta .no{ font-size:10pt; font-weight:800; color:var(--red); letter-spacing:.02em; }
.lh-meta .copytag{
  display:inline-block; margin-top:3px; padding:2px 9px; border-radius:2px;
  font-size:6.9pt; font-weight:800; letter-spacing:.14em; text-transform:uppercase;
  border:1.2px solid var(--navy); color:var(--navy);
}
.lh-meta .copytag.company{ background:var(--navy); color:#fff; }
.rule{ height:2.2px; background:linear-gradient(90deg,var(--navy) 0 62%,var(--green) 62% 100%); margin-top:1.9mm; }

/* -- document title ---------------------------------------- */
.doctitle{ text-align:center; margin:1.4mm 0 1.2mm; }
.doctitle h1{ font-size:9.4pt; font-weight:800; letter-spacing:.10em; text-transform:uppercase; }
.doctitle p{ font-size:6.9pt; color:var(--muted); letter-spacing:.04em; }

/* -- info grid --------------------------------------------- */
/* 1px gaps over a ruled background draw the grid lines, so a cell that
   spans columns can never knock the borders out of alignment. */
.grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:1px; overflow:hidden;
       border:1px solid var(--line); border-radius:2px; background:var(--line); }
.grid .cell{ padding:.7mm 1.6mm; background:#fff; min-width:0; }
.grid .cell.span2{ grid-column:span 2; }
.grid .k{ font-size:5.8pt; text-transform:uppercase; letter-spacing:.09em; color:var(--muted); font-weight:700; }
.grid .v{ font-size:7.6pt; font-weight:600; overflow-wrap:anywhere; }

/* -- earnings / deductions --------------------------------- */
.split{ display:grid; grid-template-columns:1fr 1fr; gap:2.4mm; margin-top:1.4mm; }
.amt-head{
  font-size:7pt; font-weight:800; letter-spacing:.12em; text-transform:uppercase;
  color:#fff; background:var(--navy); padding:.7mm 1.6mm; border-radius:2px 2px 0 0;
}
.amt-head.ded{ background:#7a1d1d; }
.amt-wrap{ border:1px solid var(--line); border-top:0; border-radius:0 0 2px 2px; }
table.amt{ width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
table.amt td{ padding:.42mm 1.6mm; border-bottom:1px solid #e6e9ee; font-size:7.2pt; }
table.amt td.n{ text-align:right; white-space:nowrap; }
table.amt tr.sum td{ border-top:1.2px solid var(--ink); border-bottom:0; font-weight:800; padding-top:.7mm; }
table.amt tbody tr:last-child td{ border-bottom:0; }

/* -- net pay ----------------------------------------------- */
.net{
  margin-top:1.4mm; border:1.4px solid var(--green); border-radius:2px;
  display:flex; align-items:stretch; overflow:hidden;
}
.net .lab{ background:var(--green); color:#fff; padding:.9mm 2.2mm; font-size:7.2pt; font-weight:800;
           letter-spacing:.1em; text-transform:uppercase; display:flex; align-items:center; }
.net .val{ flex:1; text-align:right; padding:.7mm 2.2mm; font-size:11.4pt; font-weight:800;
           font-variant-numeric:tabular-nums; display:flex; align-items:center; justify-content:flex-end; }
.words{ font-size:6.6pt; padding-top:.8mm; }

/* -- signatures -------------------------------------------- */
.sigs{ display:grid; grid-template-columns:1fr 1fr; gap:6mm; margin-top:1.8mm; }
.sig{ text-align:center; }
.sig .box{ height:8mm; display:flex; align-items:flex-end; justify-content:center; }
.sig .box img{ max-height:8mm; max-width:100%; object-fit:contain; }
.sig .ln{ border-top:1px solid var(--ink); margin-top:1px; padding-top:.7mm; }
.sig .nm{ font-size:7.4pt; font-weight:700; text-transform:uppercase; min-height:11px; }
.sig .rl{ font-size:5.8pt; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; }
.ack{ font-size:6.4pt; color:var(--muted); margin-top:1.1mm; text-align:justify; }

/* -- document footer --------------------------------------- */
.birfoot{ margin-top:1.4mm; border-top:1px solid var(--line); padding-top:.9mm; font-size:5.7pt; color:#374151; line-height:1.22; }
.birfoot .gen{ margin-top:.4mm; color:var(--muted); font-size:5.4pt; }

/* -- report (summary / audit) documents -------------------- */
table.rep{ width:100%; border-collapse:collapse; margin-top:3mm; font-variant-numeric:tabular-nums; }
table.rep th{ background:var(--navy); color:#fff; font-size:7.4pt; letter-spacing:.06em;
              text-transform:uppercase; padding:1.8mm 2.2mm; text-align:left; }
table.rep td{ padding:1.5mm 2.2mm; border-bottom:1px solid #e6e9ee; font-size:8.4pt; }
table.rep td.n,table.rep th.n{ text-align:right; white-space:nowrap; }
table.rep th:nth-child(-n+3),table.rep td:nth-child(-n+3){ white-space:nowrap; }
table.rep tbody tr:nth-child(even){ background:#f7f8fb; }
table.rep tfoot td{ border-top:1.4px solid var(--ink); font-weight:800; background:#fff; }
.totbar{ display:grid; grid-template-columns:repeat(3,1fr); gap:2mm; margin-top:3mm; }
.tot{ border:1px solid var(--line); border-radius:2px; padding:2mm 2.6mm; }
.tot .k{ font-size:6.8pt; text-transform:uppercase; letter-spacing:.09em; color:var(--muted); font-weight:700; }
.tot .v{ font-size:11pt; font-weight:800; font-variant-numeric:tabular-nums; }

/* -- on-screen toolbar (never printed) --------------------- */
.bar{
  position:sticky; top:0; z-index:50; display:flex; gap:8px; align-items:center;
  background:#0f172a; color:#e2e8f0; padding:9px 16px; font-size:13px;
}
.bar b{ font-weight:600; }
.bar .sp{ flex:1; }
.bar button,.bar a{
  font:inherit; font-size:12.5px; border:1px solid #334155; background:#1e293b; color:#e2e8f0;
  padding:6px 14px; border-radius:6px; cursor:pointer; text-decoration:none;
}
.bar button.primary{ background:var(--green); border-color:var(--green); color:#fff; font-weight:600; }
.bar button:hover,.bar a:hover{ filter:brightness(1.15); }

@page{ size:A4 portrait; margin:8mm; }
@media print{
  html,body{ background:#fff; }
  .bar{ display:none !important; }
  .sheet{ width:auto; min-height:0; margin:0; padding:0; box-shadow:none; }
  .sheet + .sheet{ page-break-before:always; }
  .copy{ page-break-inside:avoid; }
  .copy.report{ page-break-inside:auto; }
  table.rep thead{ display:table-header-group; }
  table.rep tr{ page-break-inside:avoid; }
}
CSS;
}

/* =============================================================
 *  LETTERHEAD  (identical on every document, every copy)
 * ============================================================= */
function birLetterhead(array $cfg, string $logoUrl, string $docNo, string $dateLine, string $copyLabel = ''): string {
    // Colour the word PHARMACY green to echo the logo wordmark
    $name = preg_replace('/PHARMACY/i', '<em>$0</em>', birEsc($cfg['bir_registered_name']), 1);

    // Business style and address share one line to keep the letterhead short
    $line2 = implode(' &nbsp;&bull;&nbsp; ', array_filter([
        birEsc(trim($cfg['bir_business_style'])),
        birEsc(trim($cfg['bir_address'])),
    ], 'strlen'));

    /* Only the company TIN, and only when it has actually been filled
     * in - an empty ruled line would read as a missing registration. */
    $reg = [];
    if (trim($cfg['bir_tin']) !== '') $reg[] = '<b>TIN:</b> ' . birEsc($cfg['bir_tin']);

    $tag = '';
    if ($copyLabel !== '') {
        $cls = stripos($copyLabel, 'company') !== false ? 'copytag company' : 'copytag';
        $tag = '<div class="' . $cls . '">' . birEsc($copyLabel) . '</div>';
    }

    $docNoHtml = $docNo !== '' ? '<div>No. <span class="no">' . birEsc($docNo) . '</span></div>' : '';

    return '
<div class="lh">
  <img class="lh-logo" src="' . birEsc($logoUrl) . '" alt="L &amp; N Pharmacy">
  <div class="lh-org">
    <div class="lh-name">' . $name . '</div>
    ' . ($line2 !== '' ? '<div class="lh-sub">' . $line2 . '</div>' : '') . '
    <div class="lh-reg">' . implode(' &nbsp;&bull;&nbsp; ', $reg) . '</div>
  </div>
  <div class="lh-meta">
    ' . $docNoHtml . '
    <div>' . birEsc($dateLine) . '</div>
    ' . $tag . '
  </div>
</div>
<div class="rule"></div>';
}

/* =============================================================
 *  DOCUMENT FOOTER  (identical on every document, every copy)
 *
 *  Says what produced the paper and when. It makes no claim of
 *  registration or accreditation, because the system has none -
 *  these are internal payroll records, not registered receipts.
 * ============================================================= */
function birFooter(array $cfg): string {
    $system = trim($cfg['bir_system_name']) !== ''
        ? $cfg['bir_system_name']
        : 'Automated Payroll System';

    return '
<div class="birfoot">
  <div class="gen">Generated by ' . birEsc($system) . ' on ' . birEsc(date('M j, Y')) . '.</div>
  <div class="gen">Internal payroll document, issued for the employee&rsquo;s own records.</div>
</div>';
}

/* =============================================================
 *  ONE PAYSLIP / ACKNOWLEDGEMENT-RECEIPT COPY
 * ============================================================= */
function birPayslipCopy(array $r, array $ctx, string $copyLabel): string {
    $cfg      = $ctx['cfg'];
    $logo     = $ctx['logo'];
    $docNo    = birDocNo((int)$r['id']);
    $dateLine = 'Date: ' . $ctx['printed_on'];

    $basic     = (float)$r['gross_pay'] - (float)$r['ot_late_adj'];
    $otAdj     = (float)$r['ot_late_adj'];
    $bonus     = (float)$r['bonus'];
    $totalEarn = $basic + $otAdj + $bonus;

    $totalDed  = (float)$r['withholding_tax'] + (float)$r['sss'] + (float)$r['philhealth']
               + (float)$r['pagibig'] + (float)$r['other_deductions'];

    $sigImg = !empty($r['signature_data'])
        ? '<img src="' . birEsc($r['signature_data']) . '" alt="Employee signature">'
        : '';

    $signatory = trim($cfg['bir_signatory_name']) !== ''
        ? birEsc($cfg['bir_signatory_name'])
        : '&nbsp;';

    $periodRange = '-';
    if (!empty($r['period_start']) && !empty($r['period_end'])) {
        $periodRange = date('M j', strtotime($r['period_start'])) . ' &ndash; ' . date('M j, Y', strtotime($r['period_end']));
    }

    /* This employee's pay was changed after the period had been finalized, so
       any payslip issued earlier is superseded. Say so on the paper. */
    $revBox = !empty($r['revised_after_finalize'])
        ? '<div style="margin:2mm 0;padding:1.6mm 2.4mm;border:1.1px solid #92400e;border-radius:1mm;'
        . 'background:#fffbeb;color:#92400e;font-size:7pt;font-weight:700;">'
        . 'REVISED PAYSLIP - these figures were corrected after '
        . birEsc($ctx['period_label'] ?? 'this pay period') . ' was finalized'
        . (!empty($r['revised_at']) ? ' on ' . date('M j, Y g:i A', strtotime($r['revised_at'])) : '')
        . '. This copy supersedes any payslip issued earlier for the same period.</div>'
        : '';

    /* Attendance behind Basic Pay: absences and undertime (already taken off
       it), paid leave and days off - whichever there were */
    $num   = fn($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
    $less  = fn($amt) => (float)$amt > 0 ? ' - ' . birPeso($amt) . ' less in Basic Pay' : '';
    $parts = [];
    if ((float)($r['absent_days'] ?? 0) > 0)
        $parts[] = 'Unpaid days (absent, or before the hire date): <b>' . $num($r['absent_days']) . ' day(s)</b>' . $less($r['absent_deduction'] ?? 0);
    if ((float)($r['undertime_hours'] ?? 0) > 0)
        $parts[] = 'Undertime: <b>' . $num($r['undertime_hours']) . ' h</b>' . $less($r['undertime_deduction'] ?? 0);
    if ((float)($r['leave_days'] ?? 0) > 0)
        $parts[] = 'Approved leave: <b>' . $num($r['leave_days']) . ' day(s)</b>, paid';
    if ((float)($r['days_off'] ?? 0) > 0)
        $parts[] = 'Days off: <b>' . $num($r['days_off']) . '</b>';
    $attLine = $parts
        ? '<div style="margin:1.4mm 0;font-size:7pt;color:#374151;">' . implode(' &nbsp;&middot;&nbsp; ', $parts) . '</div>'
        : '';

    return '
<div class="copy">
  ' . birLetterhead($cfg, $logo, $docNo, $dateLine, $copyLabel) . '

  <div class="doctitle">
    <h1>Payslip &amp; Acknowledgement Receipt</h1>
  </div>

  ' . $revBox . '

  <div class="grid">
    <div class="cell"><div class="k">Employee No.</div><div class="v">' . birEsc($r['emp_id']) . '</div></div>
    <div class="cell span2"><div class="k">Employee Name</div><div class="v">' . birEsc($r['emp_name']) . '</div></div>
    <div class="cell"><div class="k">Pay Period</div><div class="v" style="font-size:7.8pt;">' . birEsc($ctx['period_label']) . '</div></div>

    <div class="cell"><div class="k">Position</div><div class="v" style="font-size:8pt;">' . (birEsc($r['position'] ?? '') ?: '-') . '</div></div>
    <div class="cell"><div class="k">Branch</div><div class="v" style="font-size:8pt;">' . (birEsc($r['branch'] ?? '') ?: '-') . '</div></div>
    <div class="cell"><div class="k">Covered Dates</div><div class="v" style="font-size:8pt;">' . $periodRange . '</div></div>
    <div class="cell"><div class="k">Hrs / OT / Late</div><div class="v" style="font-size:8pt;">'
      . number_format((float)$r['hours_worked'], 1) . ' / '
      . number_format((float)$r['overtime_hours'], 1) . ' / '
      . number_format((float)$r['late_hours'], 1) . '</div></div>
  </div>
  ' . $attLine . '

  <div class="split">
    <div>
      <div class="amt-head">Earnings</div>
      <div class="amt-wrap"><table class="amt"><tbody>
        <tr><td>Basic Pay</td><td class="n">' . birPeso($basic) . '</td></tr>
        <tr><td>Overtime less Tardiness</td><td class="n">' . birPeso($otAdj) . '</td></tr>
        <tr><td>Bonus / Allowance</td><td class="n">' . birPeso($bonus) . '</td></tr>
        <tr class="sum"><td>Gross Pay</td><td class="n">' . birPeso($totalEarn) . '</td></tr>
      </tbody></table></div>
    </div>
    <div>
      <div class="amt-head ded">Deductions</div>
      <div class="amt-wrap"><table class="amt"><tbody>
        <tr><td>Withholding Tax' . ((float)$r['withholding_tax'] < 0 ? ' (refund of tax withheld earlier this month)' : '') . '</td><td class="n">' . birPeso($r['withholding_tax']) . '</td></tr>
        <tr><td>SSS Contribution</td><td class="n">' . birPeso($r['sss']) . '</td></tr>
        <tr><td>PhilHealth</td><td class="n">' . birPeso($r['philhealth']) . '</td></tr>
        <tr><td>Pag-IBIG (HDMF)</td><td class="n">' . birPeso($r['pagibig']) . '</td></tr>
        <tr><td>Other Deductions</td><td class="n">' . birPeso($r['other_deductions']) . '</td></tr>
        <tr class="sum"><td>Total Deductions</td><td class="n">' . birPeso($totalDed) . '</td></tr>
      </tbody></table></div>
    </div>
  </div>

  <div class="net">
    <div class="lab">Net Pay Received</div>
    <div class="val">' . birPeso($r['net_pay']) . '</div>
  </div>
  <div class="words"><b>Amount in words:</b> ' . birEsc(amountInWords((float)$r['net_pay'])) . '</div>

  <div class="sigs">
    <div class="sig">
      <div class="box">' . $sigImg . '</div>
      <div class="ln"><div class="nm">' . birEsc($r['emp_name']) . '</div>
      <div class="rl">Received by - Employee signature over printed name</div></div>
    </div>
    <div class="sig">
      <div class="box"></div>
      <div class="ln"><div class="nm">' . $signatory . '</div>
      <div class="rl">' . birEsc($cfg['bir_signatory_position']) . ' - Authorised signatory</div></div>
    </div>
  </div>

  <div class="ack">I acknowledge receipt from ' . birEsc($cfg['bir_registered_name']) . ' of the net amount above as full payment of my salaries, wages and benefits for the pay period shown, and that the deductions listed were explained to me and are correct.</div>

  ' . birFooter($cfg) . '
</div>';
}

/* =============================================================
 *  FULL PAYSLIP / RECEIPT DOCUMENT
 *  $ctx['copies']: 'both' | 'employee' | 'company'
 * ============================================================= */
function birRenderPayslipDoc(array $rows, array $ctx): string {
    $copies = $ctx['copies'] ?? 'both';
    $title  = $ctx['title']  ?? 'Payslip & Acknowledgement Receipt';

    $labels = $copies === 'employee' ? ['EMPLOYEE COPY']
            : ($copies === 'company' ? ['COMPANY COPY']
            : ['EMPLOYEE COPY', 'COMPANY COPY']);

    $sheets = '';
    foreach ($rows as $r) {
        $blocks = [];
        foreach ($labels as $lab) $blocks[] = birPayslipCopy($r, $ctx, $lab);
        $inner = count($blocks) > 1
            ? $blocks[0] . '<div class="cut"><span>cut here</span></div>' . $blocks[1]
            : $blocks[0];
        $sheets .= '<div class="sheet">' . $inner . '</div>';
    }

    if ($sheets === '') {
        $sheets = '<div class="sheet"><div class="copy" style="text-align:center;padding:20mm;">'
                . 'No payroll records to print for this selection.</div></div>';
    }

    $count   = count($rows);
    $barNote = ($count === 1 ? '1 document' : $count . ' documents')
             . ' &bull; ' . (count($labels) > 1 ? 'employee + company copy' : strtolower($labels[0]));

    return birShell($title, $sheets, $ctx, $barNote);
}

/* =============================================================
 *  PAYROLL SUMMARY REPORT - same letterhead, same footer
 * ============================================================= */
function birRenderSummaryDoc(array $rows, array $ctx): string {
    $cfg  = $ctx['cfg'];
    $logo = $ctx['logo'];

    $tGross = $tNet = $tTax = $tSss = $tPh = $tPi = $tBonus = $tDed = 0.0;
    $body = '';
    foreach ($rows as $i => $r) {
        $tGross += (float)$r['gross_pay'];   $tNet   += (float)$r['net_pay'];
        $tTax   += (float)$r['withholding_tax'];
        $tSss   += (float)$r['sss'];         $tPh    += (float)$r['philhealth'];
        $tPi    += (float)$r['pagibig'];     $tBonus += (float)$r['bonus'];
        $tDed   += (float)$r['other_deductions'];

        $body .= '<tr>
            <td>' . ($i + 1) . '</td>
            <td>' . birEsc($r['emp_id']) . '</td>
            <td>' . birEsc($r['emp_name']) . '</td>
            <td class="n">' . birPeso($r['gross_pay']) . '</td>
            <td class="n">' . birPeso($r['withholding_tax']) . '</td>
            <td class="n">' . birPeso($r['sss']) . '</td>
            <td class="n">' . birPeso($r['philhealth']) . '</td>
            <td class="n">' . birPeso($r['pagibig']) . '</td>
            <td class="n">' . birPeso($r['bonus']) . '</td>
            <td class="n">' . birPeso($r['other_deductions']) . '</td>
            <td class="n"><b>' . birPeso($r['net_pay']) . '</b></td>
        </tr>';
    }

    $content = '
<div class="sheet">
  <div class="copy report">
    ' . birLetterhead($cfg, $logo, '', 'Date: ' . $ctx['printed_on'], 'COMPANY COPY') . '
    <div class="doctitle">
      <h1>Payroll Register &amp; Summary</h1>
      <p>' . birEsc($ctx['period_label']) . ' &nbsp;&bull;&nbsp; ' . count($rows) . ' employee(s)</p>
    </div>

    <table class="rep">
      <thead><tr>
        <th>#</th><th>Emp No.</th><th>Employee Name</th>
        <th class="n">Gross</th><th class="n">Tax</th><th class="n">SSS</th>
        <th class="n">PhilHealth</th><th class="n">Pag-IBIG</th>
        <th class="n">Bonus</th><th class="n">Other Ded.</th><th class="n">Net Pay</th>
      </tr></thead>
      <tbody>' . ($body ?: '<tr><td colspan="11" style="text-align:center;padding:8mm;">No records.</td></tr>') . '</tbody>
      <tfoot><tr>
        <td colspan="3">TOTAL</td>
        <td class="n">' . birPeso($tGross) . '</td>
        <td class="n">' . birPeso($tTax) . '</td>
        <td class="n">' . birPeso($tSss) . '</td>
        <td class="n">' . birPeso($tPh) . '</td>
        <td class="n">' . birPeso($tPi) . '</td>
        <td class="n">' . birPeso($tBonus) . '</td>
        <td class="n">' . birPeso($tDed) . '</td>
        <td class="n">' . birPeso($tNet) . '</td>
      </tr></tfoot>
    </table>

    <div class="totbar">
      <div class="tot"><div class="k">Total Gross Payroll</div><div class="v">' . birPeso($tGross) . '</div></div>
      <div class="tot red"><div class="k">Total Deductions Withheld</div><div class="v">' . birPeso($tTax + $tSss + $tPh + $tPi + $tDed) . '</div></div>
      <div class="tot green"><div class="k">Total Net Pay Released</div><div class="v">' . birPeso($tNet) . '</div></div>
    </div>

    <div class="sigs" style="margin-top:6mm;">
      <div class="sig"><div class="box"></div><div class="ln">
        <div class="nm">' . (trim($cfg['bir_signatory_name']) !== '' ? birEsc($cfg['bir_signatory_name']) : '&nbsp;') . '</div>
        <div class="rl">' . birEsc($cfg['bir_signatory_position']) . ' - Prepared by</div></div></div>
      <div class="sig"><div class="box"></div><div class="ln">
        <div class="nm">&nbsp;</div><div class="rl">Approved by - Signature over printed name</div></div></div>
    </div>

    ' . birFooter($cfg) . '
  </div>
</div>';

    return birShell($ctx['title'] ?? 'Payroll Summary', $content, $ctx, count($rows) . ' employee(s)');
}

/* =============================================================
 *  GENERIC TABLE REPORT (print history, leave register, ...)
 *  $cols    = ['Header' => '' | 'n']   ('n' = right-aligned)
 *  $rowsHtml = pre-escaped <tr> markup
 * ============================================================= */
function birRenderReportDoc(string $heading, string $subheading, array $cols, string $rowsHtml, array $ctx): string {
    $cfg  = $ctx['cfg'];
    $logo = $ctx['logo'];

    $th = '';
    foreach ($cols as $label => $align) {
        $th .= '<th' . ($align === 'n' ? ' class="n"' : '') . '>' . birEsc($label) . '</th>';
    }

    $content = '
<div class="sheet">
  <div class="copy report">
    ' . birLetterhead($cfg, $logo, '', 'Date: ' . $ctx['printed_on'], 'COMPANY COPY') . '
    <div class="doctitle">
      <h1>' . birEsc($heading) . '</h1>
      <p>' . birEsc($subheading) . '</p>
    </div>
    <table class="rep"><thead><tr>' . $th . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>
    <div class="sigs" style="margin-top:6mm;">
      <div class="sig"><div class="box"></div><div class="ln">
        <div class="nm">' . (trim($cfg['bir_signatory_name']) !== '' ? birEsc($cfg['bir_signatory_name']) : '&nbsp;') . '</div>
        <div class="rl">' . birEsc($cfg['bir_signatory_position']) . ' - Prepared by</div></div></div>
      <div class="sig"><div class="box"></div><div class="ln">
        <div class="nm">&nbsp;</div><div class="rl">Noted by - Signature over printed name</div></div></div>
    </div>
    ' . birFooter($cfg) . '
  </div>
</div>';

    return birShell($heading, $content, $ctx, $subheading);
}

/* =============================================================
 *  HTML SHELL - toolbar + auto-print
 * ============================================================= */
function birShell(string $title, string $content, array $ctx, string $barNote = ''): string {
    $css      = str_replace('%LOGO%', $ctx['logo'], birPrintCss());
    $autoOpen = !empty($ctx['auto_print']) ? 'true' : 'false';

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . birEsc($title) . ' - ' . birEsc($ctx['cfg']['company_name']) . '</title>
<style>' . $css . '</style>
</head>
<body>
<div class="bar">
  <b>' . birEsc($title) . '</b>
  <span style="opacity:.7;">' . $barNote . '</span>
  <span class="sp"></span>
  <button class="primary" onclick="window.print()">Print</button>
  <button onclick="window.close()">Close</button>
</div>
' . $content . '
<script>
if (' . $autoOpen . ') {
  window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 350); });
}
</script>
</body>
</html>';
}

/* =============================================================
 *  BONUS / DEDUCTION ADJUSTMENT
 *  same letterhead, same footer, same two-copy layout as the
 *  payslip receipt - a bonus payout is money handed to the employee
 *  and needs its own acknowledgement; a deduction needs the
 *  employee's conforme.
 * ============================================================= */

/* Adjustments run their own automatic series so their numbers can never
 * collide with the payslip receipts: ADJ-000001, ADJ-000002, … */
function birAdjDocNo(int $id): string {
    return 'ADJ-' . str_pad((string)birSerialFor('ADJ', $id), 6, '0', STR_PAD_LEFT);
}

function birAdjustmentCopy(array $h, array $ctx, string $copyLabel): string {
    $cfg     = $ctx['cfg'];
    $isBonus = strcasecmp((string)$h['entry_type'], 'Bonus') === 0;

    $heading = $isBonus ? 'Bonus Advice &amp; Acknowledgement Receipt'
                        : 'Deduction Advice &amp; Employee Conforme';
    $amtLab  = $isBonus ? 'Amount Received' : 'Amount Deducted';
    $sigRole = $isBonus ? 'Received by - Employee signature over printed name'
                        : 'Conforme - Employee signature over printed name';
    $ackText = $isBonus
        ? 'I acknowledge having received from ' . birEsc($cfg['bir_registered_name']) . ' the amount stated above as the bonus / additional compensation described, and that it forms part of my compensation for the pay period indicated.'
        : 'I confirm that the deduction stated above was explained to me, that I consent to it being withheld from my salary for the pay period indicated, and that the reason given is correct.';

    $signatory = trim($cfg['bir_signatory_name']) !== '' ? birEsc($cfg['bir_signatory_name']) : '&nbsp;';
    $entryDate = !empty($h['entry_date']) ? date('M j, Y', strtotime($h['entry_date'])) : '-';

    /* An entry recorded after the period was already finalized is a correction.
       It must say so on the paper, otherwise two receipts for the same pay
       period look equally original. */
    $cycle  = (int)($h['finalize_cycle'] ?? 0);
    $revBox = $cycle > 0
        ? '<div style="margin:2mm 0;padding:1.6mm 2.4mm;border:1.1px solid #92400e;border-radius:1mm;'
        . 'background:#fffbeb;color:#92400e;font-size:7pt;font-weight:700;">'
        . 'REVISION - recorded after ' . birEsc($ctx['period_label'] ?? 'this pay period')
        . ' had already been finalized (' . $cycle . '&times;). This entry corrects the period and '
        . 'the employee&rsquo;s pay was recomputed accordingly.</div>'
        : '';

    return '
<div class="copy">
  ' . birLetterhead($cfg, $ctx['logo'], birAdjDocNo((int)$h['id']), 'Date: ' . $ctx['printed_on'], $copyLabel) . '

  <div class="doctitle">
    <h1>' . $heading . '</h1>
  </div>

  ' . $revBox . '

  <div class="grid">
    <div class="cell"><div class="k">Entry Date</div><div class="v">' . $entryDate . '</div></div>
    <div class="cell"><div class="k">Employee No.</div><div class="v">' . birEsc($h['emp_id']) . '</div></div>
    <div class="cell span2"><div class="k">Employee Name</div><div class="v">' . birEsc($h['emp_name']) . '</div></div>

    <div class="cell"><div class="k">Type</div><div class="v">' . birEsc($h['entry_type']) . '</div></div>
    <div class="cell"><div class="k">Pay Period</div><div class="v" style="font-size:7.4pt;">' . (birEsc($ctx['period_label'] ?? '') ?: '-') . '</div></div>
    <div class="cell span2"><div class="k">Reason / Particulars</div><div class="v" style="font-size:7.6pt;font-weight:500;">' . (birEsc($h['reason']) ?: '-') . '</div></div>
  </div>

  <div class="net">
    <div class="lab">' . $amtLab . '</div>
    <div class="val">' . birPeso($h['amount']) . '</div>
  </div>
  <div class="words"><b>Amount in words:</b> ' . birEsc(amountInWords((float)$h['amount'])) . '</div>

  <div class="sigs">
    <div class="sig">
      <div class="box"></div>
      <div class="ln"><div class="nm">' . birEsc($h['emp_name']) . '</div>
      <div class="rl">' . $sigRole . '</div></div>
    </div>
    <div class="sig">
      <div class="box"></div>
      <div class="ln"><div class="nm">' . (birEsc($h['processed_by'] ?? '') ?: $signatory) . '</div>
      <div class="rl">' . birEsc($cfg['bir_signatory_position']) . ' - Processed by</div></div>
    </div>
  </div>

  <div class="ack">' . $ackText . '</div>

  ' . birFooter($cfg) . '
</div>';
}

function birRenderAdjustmentDoc(array $rows, array $ctx): string {
    $copies = $ctx['copies'] ?? 'both';
    $labels = $copies === 'employee' ? ['EMPLOYEE COPY']
            : ($copies === 'company' ? ['COMPANY COPY']
            : ['EMPLOYEE COPY', 'COMPANY COPY']);

    $sheets = '';
    foreach ($rows as $h) {
        $blocks = [];
        foreach ($labels as $lab) $blocks[] = birAdjustmentCopy($h, $ctx, $lab);
        $sheets .= '<div class="sheet">' . (count($blocks) > 1
            ? $blocks[0] . '<div class="cut"><span>cut here</span></div>' . $blocks[1]
            : $blocks[0]) . '</div>';
    }

    if ($sheets === '') {
        $sheets = '<div class="sheet"><div class="copy" style="text-align:center;padding:20mm;">'
                . 'No adjustment record to print for this selection.</div></div>';
    }

    $barNote = (count($rows) === 1 ? '1 document' : count($rows) . ' documents')
             . ' &bull; ' . (count($labels) > 1 ? 'employee + company copy' : strtolower($labels[0]));

    return birShell($ctx['title'] ?? 'Adjustment Receipt', $sheets, $ctx, $barNote);
}
