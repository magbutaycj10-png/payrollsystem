// assets/js/history.js
// Bonus & deduction printing. Both documents come from print-doc.php so the
// transaction receipt and the register carry the same L&N Pharmacy letterhead
// and footer as every other document the system issues.

function openDoc(params) {
    window.open('print-doc.php?' + new URLSearchParams(params).toString(),
                '_blank', 'width=980,height=760');
}

/* One entry — advice + acknowledgement receipt, employee and company copy. */
function printReceipt(id) {
    openDoc({ doc: 'adjustment', id: id, copies: 'both' });
}

/* Register of everything currently filtered on screen — the "corrections
   only" filter included, so the printed register matches the table. */
function printAll() {
    const q = new URLSearchParams(window.location.search);
    openDoc({
        doc:       'adjustments',
        emp_id:    q.get('emp_id')    || '',
        period_id: q.get('period_id') || '',
        type:      q.get('type')      || '',
        rev:       q.get('rev')       || ''
    });
}
