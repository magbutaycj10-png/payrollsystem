// assets/js/reports.js
// Requires window.REPORTS_DATA = { company, period, period_id, rows }
//
// Printing is NOT built here any more. Every document — the individual
// payslip, the batch run and the payroll register — is rendered by
// print-doc.php from the one shared template in includes/bir-print.php,
// so all of them come out with the same L&N Pharmacy letterhead, the same
// employee/company copies and the same footer block.

/* Which copies to issue — read from the picker in the page header. */
function selectedCopies() {
    const el = document.getElementById('copyMode');
    return el ? el.value : 'both';
}

function openDoc(params) {
    const qs = new URLSearchParams(params).toString();
    window.open('print-doc.php?' + qs, '_blank', 'width=980,height=760');
}

/* One employee — payslip + acknowledgement receipt. */
function printPayslip(payrollId) {
    openDoc({ doc: 'payslip', payroll_id: payrollId, copies: selectedCopies() });
}

/* Every employee in the selected period, one sheet each. */
function printAllPayslips() {
    if (!window.REPORTS_DATA.rows.length) { alert('No data to print.'); return; }
    openDoc({ doc: 'payslip', period: window.REPORTS_DATA.period_id, copies: selectedCopies() });
}

/* Payroll register + totals for the selected period. */
function printSummary() {
    if (!window.REPORTS_DATA.rows.length) { alert('No data to print.'); return; }
    openDoc({ doc: 'summary', period: window.REPORTS_DATA.period_id });
}

function exportCSV() {
    const rows = window.REPORTS_DATA.rows;
    if (!rows.length) { alert('No data to export.'); return; }

    /* Gross Pay is everything earned (basic + OT − late), as on the timesheet */
    const headers = ['ID','Name','Hours','Overtime','Late','Basic Pay','OT-Late','Gross Pay','SSS','PhilHealth','Pag-IBIG','Tax','Bonus','Deductions','Net Pay'];
    const csv = [
        headers.join(','),
        ...rows.map(r => [
            r.emp_id, `"${r.emp_name}"`, r.hours_worked, r.overtime_hours, r.late_hours,
            (r.gross_pay - r.ot_late_adj).toFixed(2), r.ot_late_adj, r.gross_pay, r.sss, r.philhealth,
            r.pagibig, r.withholding_tax, r.bonus, r.other_deductions, r.net_pay
        ].join(','))
    ].join('\n');

    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = `payroll_${window.REPORTS_DATA.period.replace(/\s/g, '_')}.csv`;
    a.click();

    logPrint(`Payroll CSV Export — ${window.REPORTS_DATA.period}`, 'PDF Export', window.REPORTS_DATA.period_id);
}

/* print-doc.php writes its own audit entry server-side; this is only
   needed for the CSV export, which never touches that endpoint. */
function logPrint(doc, type, period_id) {
    fetch('api/log-print.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ doc, type, period_id })
    });
}
