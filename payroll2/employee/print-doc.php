<?php
/* =============================================================
 * employee/print-doc.php — the print endpoint for the employee portal.
 *
 * Serves the very same payslip & acknowledgement receipt as the
 * admin side, but the WHERE clause is pinned to the signed-in employee's
 * emp_id, so an employee can only ever print their own pay records. The
 * payroll_id in the query string is checked against that, never trusted.
 *
 * Query string
 *   payroll_id=<id>   one payslip (must belong to the signed-in employee)
 *   (omitted)         every payslip this employee has
 *   copies=both|employee|company    default: employee copy only
 *   sig=0             print a blank line to sign in ink instead of the
 *                     stored e-signature
 *   auto=0            do not fire the print dialog on load
 * ============================================================= */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/bir-print.php';
requireEmployee();

$db = getDB();
$e  = emp();

if ($e['id'] === '') {
    http_response_code(403);
    exit('No employee is signed in.');
}

$payrollId = (int)($_GET['payroll_id'] ?? 0);
$copies    = $_GET['copies'] ?? 'employee';
if (!in_array($copies, ['both', 'employee', 'company'], true)) $copies = 'employee';
$withSig   = ($_GET['sig'] ?? '1') !== '0';

$ctx = [
    'cfg'        => birConfig(),
    /* absolute from the document root — this file sits one level deeper
       than the admin portal's print-doc.php */
    'logo'       => '/assets/images/logo.png',
    'printed_on' => date('M j, Y g:i A'),
    'auto_print' => ($_GET['auto'] ?? '1') !== '0',
    'copies'     => $copies,
    'title'      => 'Payslip & Acknowledgement Receipt',
];

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
     WHERE p.emp_id = ?
";

/* emp_id is always in the WHERE clause, so a guessed payroll_id
   belonging to somebody else simply returns nothing. */
if ($payrollId) {
    $st = $db->prepare($select . ' AND p.id = ?');
    $st->execute([$e['id'], $payrollId]);
} else {
    $st = $db->prepare($select . ' ORDER BY pp.period_start DESC, pp.id DESC');
    $st->execute([$e['id']]);
}
$rows = $st->fetchAll();

if (!$withSig) {
    foreach ($rows as &$r) { $r['signature_data'] = null; }
    unset($r);
}

$ctx['period_label'] = $rows[0]['period_label'] ?? '';

/* Same print audit trail the admin portal writes to. */
try {
    $db->prepare('INSERT INTO print_log (document_name, document_type, period_id, printed_by)
                  VALUES (?,?,?,?)')
       ->execute([
           $payrollId
               ? 'Payslip / Acknowledgement Receipt — ' . ($rows[0]['emp_name'] ?? $e['name'])
               : 'Payslips (All) — ' . ($e['name'] ?: $e['id']),
           'Employee Self-Service',
           $payrollId ? (int)($rows[0]['period_id'] ?? 0) ?: null : null,
           $e['name'] ?: $e['id'],
       ]);
} catch (PDOException $ex) { /* auditing must never block a print */ }

echo birRenderPayslipDoc($rows, $ctx);
