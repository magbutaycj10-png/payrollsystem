<?php
/* =============================================================
 * print-doc.php - the single print endpoint for the admin portal.
 *
 * Every "Print" button in the admin side opens this page, so the
 * individual payslip, the batch "Print All", the signed receipt and
 * the summary/audit reports all come out of the same shared
 * template in includes/bir-print.php.
 *
 * Query string
 *   doc=payslip  (default)  payroll_id=<id>   one employee
 *                           period=<id>       every employee in a period
 *                           copies=both|employee|company   (default both)
 *                           sig=0             suppress the stored e-signature
 *                                             (prints a blank line to sign in ink)
 *   doc=summary  period=<id>    payroll register + totals
 *   doc=printlog                print/export audit log
 *   doc=leaves                  leave request register
 *   auto=0                      do not fire the print dialog on load
 * ============================================================= */

require 'includes/bir-print.php';
requireAuth();

$db   = getDB();
$cfg  = birConfig();
$doc  = $_GET['doc'] ?? 'payslip';
$auto = ($_GET['auto'] ?? '1') !== '0';

$ctx = [
    'cfg'        => $cfg,
    'logo'       => 'assets/images/logo.png',
    'printed_on' => date('M j, Y g:i A'),
    'auto_print' => $auto,
];

/* Record every generated document in the print audit trail. */
function logPrintDoc(PDO $db, string $name, string $type = 'Physical Print', ?int $periodId = null): void {
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $who = trim($_SESSION['admin'] ?? '') ?: 'Admin';
        $db->prepare('INSERT INTO print_log (document_name, document_type, period_id, printed_by) VALUES (?,?,?,?)')
           ->execute([$name, $type, $periodId ?: null, $who]);
    } catch (PDOException $e) { /* auditing must never block a print */ }
}

/* -------------------------------------------------------------
 * doc=payslip - payslip & acknowledgement receipt
 * ----------------------------------------------------------- */
if ($doc === 'payslip') {
    $payrollId = (int)($_GET['payroll_id'] ?? 0);
    $periodId  = (int)($_GET['period'] ?? 0);
    $copies    = $_GET['copies'] ?? 'both';
    if (!in_array($copies, ['both', 'employee', 'company'], true)) $copies = 'both';
    $withSig   = ($_GET['sig'] ?? '1') !== '0';

    $select = "
        SELECT p.*, pp.period_label, pp.period_start, pp.period_end,
               e.position, e.branch,
               (SELECT ps.signature_data
                  FROM payslip_signatures ps
                 WHERE ps.payroll_id = p.id
               AND (ps.net_signed IS NULL OR ABS(ps.net_signed - p.net_pay) < 0.005)
              ORDER BY ps.signed_at DESC LIMIT 1) AS signature_data
          FROM payroll p
          JOIN payroll_periods pp ON pp.id = p.period_id
     LEFT JOIN employees e        ON e.emp_id = p.emp_id
    ";

    if ($payrollId) {
        $st = $db->prepare($select . ' WHERE p.id = ?');
        $st->execute([$payrollId]);
    } else {
        $st = $db->prepare($select . ' WHERE p.period_id = ? ORDER BY p.emp_name ASC');
        $st->execute([$periodId]);
    }
    $rows = $st->fetchAll();

    if (!$withSig) {
        foreach ($rows as &$r) { $r['signature_data'] = null; }
        unset($r);
    }

    $ctx['period_label'] = $rows[0]['period_label'] ?? '';
    $ctx['copies']       = $copies;
    $ctx['title']        = 'Payslip & Acknowledgement Receipt';

    $logName = $payrollId
        ? 'Payslip / Acknowledgement Receipt - ' . ($rows[0]['emp_name'] ?? '#' . $payrollId)
        : 'Payslip / Acknowledgement Receipts (All) - ' . $ctx['period_label'];
    logPrintDoc($db, $logName, 'Physical Print', (int)($rows[0]['period_id'] ?? $periodId));

    echo birRenderPayslipDoc($rows, $ctx);
    exit;
}

/* -------------------------------------------------------------
 * doc=summary - payroll register with totals
 * ----------------------------------------------------------- */
if ($doc === 'summary') {
    $periodId = (int)($_GET['period'] ?? 0);

    $per = $db->prepare('SELECT * FROM payroll_periods WHERE id = ?');
    $per->execute([$periodId]);
    $per = $per->fetch();

    $st = $db->prepare('SELECT * FROM payroll WHERE period_id = ? ORDER BY emp_name ASC');
    $st->execute([$periodId]);
    $rows = $st->fetchAll();

    $ctx['period_label'] = $per['period_label'] ?? '-';
    $ctx['title']        = 'Payroll Register & Summary';

    logPrintDoc($db, 'Payroll Register & Summary - ' . $ctx['period_label'], 'Physical Print', $periodId);

    echo birRenderSummaryDoc($rows, $ctx);
    exit;
}

/* -------------------------------------------------------------
 * doc=adjustment - one bonus / deduction acknowledgement receipt
 * ----------------------------------------------------------- */
if ($doc === "adjustment") {
    $id     = (int)($_GET["id"] ?? 0);
    $copies = $_GET["copies"] ?? "both";
    if (!in_array($copies, ["both", "employee", "company"], true)) $copies = "both";

    $st = $db->prepare("
        SELECT h.*, pp.period_label
          FROM bonus_deduction_history h
     LEFT JOIN payroll_periods pp ON pp.id = h.period_id
         WHERE h.id = ?
    ");
    $st->execute([$id]);
    $rows = $st->fetchAll();

    $ctx["period_label"] = $rows[0]["period_label"] ?? "";
    $ctx["copies"]       = $copies;
    $ctx["title"]        = ($rows[0]["entry_type"] ?? "Adjustment") . " Advice & Receipt";

    if ($rows) {
        logPrintDoc($db, $rows[0]["entry_type"] . " Receipt - " . $rows[0]["emp_name"],
                    "Physical Print", (int)($rows[0]["period_id"] ?? 0));
    }

    echo birRenderAdjustmentDoc($rows, $ctx);
    exit;
}

/* -------------------------------------------------------------
 * doc=adjustments - bonus & deduction register (honours the same
 * emp_id / period_id / type filters as history.php)
 * ----------------------------------------------------------- */
if ($doc === "adjustments") {
    $fEmp    = $_GET["emp_id"] ?? "";
    $fPeriod = (int)($_GET["period_id"] ?? 0);
    $fType   = $_GET["type"] ?? "";
    $fRev    = ($_GET["rev"] ?? "") === "1";

    $where = "1=1"; $params = [];
    if ($fEmp    !== "") { $where .= " AND h.emp_id = ?";     $params[] = $fEmp; }
    if ($fPeriod)        { $where .= " AND h.period_id = ?";  $params[] = $fPeriod; }
    if ($fType   !== "") { $where .= " AND h.entry_type = ?"; $params[] = $fType; }
    if ($fRev)           { $where .= " AND h.finalize_cycle > 0"; }

    $st = $db->prepare("
        SELECT h.*, pp.period_label
          FROM bonus_deduction_history h
     LEFT JOIN payroll_periods pp ON pp.id = h.period_id
         WHERE $where
      ORDER BY h.entry_date DESC, h.id DESC
         LIMIT 300
    ");
    $st->execute($params);
    $rows = $st->fetchAll();

    $tBonus = $tDed = 0.0;
    $body = "";
    foreach ($rows as $i => $r) {
        $isBonus = strcasecmp((string)$r["entry_type"], "Bonus") === 0;
        $isBonus ? $tBonus += (float)$r["amount"] : $tDed += (float)$r["amount"];

        $body .= "<tr>
            <td>" . ($i + 1) . "</td>
            <td>" . birEsc(date("M j, Y", strtotime($r["entry_date"]))) . "</td>
            <td>" . birEsc($r["emp_id"]) . "</td>
            <td>" . birEsc($r["emp_name"]) . "</td>
            <td>" . birEsc($r["entry_type"]) . "</td>
            <td>" . birEsc($r["reason"]) . "</td>
            <td>" . birEsc($r["period_label"] ?? "") . "</td>
            <td>" . ((int)($r["finalize_cycle"] ?? 0) > 0
                     ? "Revision (after finalize #" . (int)$r["finalize_cycle"] . ")"
                     : "Original") . "</td>
            <td>" . birEsc($r["processed_by"] ?? "") . "</td>
            <td class=\"n\"><b>" . birPeso($r["amount"]) . "</b></td>
        </tr>";
    }
    if ($body === "") $body = "<tr><td colspan=\"10\" style=\"text-align:center;padding:8mm;\">No adjustment records for this selection.</td></tr>";
    else $body .= "<tr><td colspan=\"9\" style=\"border-top:1.4px solid #111827;font-weight:800;\">TOTAL - bonus "
                . birPeso($tBonus) . " / deduction " . birPeso($tDed) . "</td>"
                . "<td class=\"n\" style=\"border-top:1.4px solid #111827;font-weight:800;\">" . birPeso($tBonus - $tDed) . "</td></tr>";

    logPrintDoc($db, "Bonus & Deduction Register", "Physical Print", $fPeriod ?: null);

    $sub = count($rows) . " entry(ies)"
         . ($fType !== "" ? " - " . $fType . " only" : "")
         . ($fRev ? " - corrections made after finalize only" : "");

    echo birRenderReportDoc(
        "Bonus & Deduction Register",
        $sub,
        ["#" => "", "Date" => "", "Emp No." => "", "Employee Name" => "", "Type" => "",
         "Reason / Particulars" => "", "Pay Period" => "", "Entry Status" => "",
         "Processed By" => "", "Amount" => "n"],
        $body,
        $ctx
    );
    exit;
}
/* -------------------------------------------------------------
 * doc=printlog - print / export audit trail
 * ----------------------------------------------------------- */
if ($doc === 'printlog') {
    $logs = $db->query("
        SELECT pl.*, pp.period_label
          FROM print_log pl
     LEFT JOIN payroll_periods pp ON pl.period_id = pp.id
      ORDER BY pl.log_datetime DESC
         LIMIT 200
    ")->fetchAll();

    $body = '';
    foreach ($logs as $i => $l) {
        $body .= '<tr>
            <td>' . ($i + 1) . '</td>
            <td>' . birEsc(date('M j, Y g:i A', strtotime($l['log_datetime']))) . '</td>
            <td>' . birEsc($l['document_name']) . '</td>
            <td>' . birEsc($l['document_type']) . '</td>
            <td>' . birEsc($l['period_label'] ?? '') . '</td>
            <td>' . birEsc($l['printed_by'] ?? '') . '</td>
        </tr>';
    }
    if ($body === '') $body = '<tr><td colspan="6" style="text-align:center;padding:8mm;">No print records yet.</td></tr>';

    echo birRenderReportDoc(
        'Document Print & Export Register',
        'Audit trail of issued documents - ' . count($logs) . ' most recent entries',
        ['#' => '', 'Date & Time' => '', 'Document Issued' => '', 'Type' => '', 'Pay Period' => '', 'Issued By' => ''],
        $body,
        $ctx
    );
    exit;
}

/* -------------------------------------------------------------
 * doc=leaves - leave request register
 * ----------------------------------------------------------- */
if ($doc === 'leaves') {
    $rows = $db->query("SELECT * FROM leave_requests ORDER BY created_at DESC LIMIT 300")->fetchAll();

    $body = '';
    foreach ($rows as $i => $r) {
        $body .= '<tr>
            <td>' . ($i + 1) . '</td>
            <td>' . birEsc($r['emp_id']) . '</td>
            <td>' . birEsc($r['emp_name']) . '</td>
            <td>' . birEsc($r['leave_type']) . '</td>
            <td>' . birEsc(date('M j, Y', strtotime($r['date_from']))) . ' &ndash; '
                  . birEsc(date('M j, Y', strtotime($r['date_to']))) . '</td>
            <td>' . birEsc($r['status']) . '</td>
            <td>' . birEsc($r['reviewed_by'] ?? '') . '</td>
        </tr>';
    }
    if ($body === '') $body = '<tr><td colspan="7" style="text-align:center;padding:8mm;">No leave requests on file.</td></tr>';

    logPrintDoc($db, 'Leave Request Register', 'Physical Print', null);

    echo birRenderReportDoc(
        'Employee Leave Register',
        count($rows) . ' request(s) on file',
        ['#' => '', 'Emp No.' => '', 'Employee Name' => '', 'Type' => '', 'Inclusive Dates' => '', 'Status' => '', 'Reviewed By' => ''],
        $body,
        $ctx
    );
    exit;
}

http_response_code(400);
echo 'Unknown document type.';
