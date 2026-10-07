// assets/js/payroll.js
// Period state for Payroll Processing. Every action goes through
// api/update-payroll.php, which writes the finalize / re-open trail to
// period_audit — the page reloads afterwards so the trail and the
// "Revised" flags on screen come from the database, not from here.

async function periodAction(action, extra) {
    const resp = await fetch('api/update-payroll.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(Object.assign({ action: action, period_id: window.PAYROLL_PAGE.period_id }, extra || {}))
    });
    return resp.json();
}

async function unlockPayroll() {
    if (!confirm('Re-open this period?\n\n'
        + 'Payroll records become editable again and you will be able to add bonuses and deductions. '
        + 'Every bonus and deduction already recorded is kept.\n\n'
        + 'Anything you change from now on is stored as a revision and the employees affected are '
        + 'flagged "Revised". Finalize the period again when you are done.')) return;

    const data = await periodAction('unlock');

    if (data.success) {
        showAlert('Period re-opened (re-open #' + data.reopen_count + '). '
                + 'Changes made now are recorded as revisions — finalize again when you are done.',
                  'alert-success');
        setTimeout(() => location.reload(), 1400);
    } else {
        showAlert('Error: ' + data.error, 'alert-error');
    }
}

/* Rebuild the period from its saved days with the current rules and Settings (open periods only) */
async function recomputePayroll() {
    if (!confirm('Recompute this period?\n\n'
        + 'Every employee\'s pay is rebuilt from the days saved for this period, with the current rates, rules and Settings. '
        + 'Open cut-offs after it in the same month are brought up to date too. Bonuses and deductions already recorded are kept.')) return;

    const data = await periodAction('recompute');

    if (data.success) {
        showAlert('Recomputed ' + data.recomputed + ' employee line(s).', 'alert-success');
        setTimeout(() => location.reload(), 1200);
    } else {
        showAlert('Error: ' + data.error, 'alert-error');
    }
}

async function finalizePayroll() {
    if (!confirm('Finalize this payroll period?\n\n'
        + 'Records are locked and no bonus or deduction can be added until the period is unlocked again.')) return;

    let extra = {};
    let data  = await periodAction('finalize', extra);

    /* The server asks before locking in something that looks wrong: figures that are out of date (Recompute is the
       cure), or a negative net pay. Each question is asked once; answering yes repeats the finalize with that OK. */
    for (let asked = 0; asked < 2 && (data.error === 'stale' || data.error === 'negative_net'); asked++) {
        const q = data.error === 'stale'
            ? data.message + '\n\nPress Cancel, then "Recompute", to bring them up to date.\nFinalize with the figures as they are?'
            : data.message + '\n\nFinalize anyway?';
        if (!confirm(q)) return;
        extra = Object.assign({}, extra, data.error === 'stale' ? { ignore_drift: true } : { allow_negative: true });
        data  = await periodAction('finalize', extra);
    }

    if (data.success) {
        let msg = 'Payroll finalized and period locked. Payslips can now be issued.';
        if (data.cycle > 1) {
            msg = 'Period re-finalized (finalize #' + data.cycle + ') with ' + data.revised
                + ' revised employee row(s). Reissue those payslips — they replace the earlier copies.';
        }
        showAlert(msg, 'alert-success');
        setTimeout(() => location.reload(), 1800);
    } else {
        showAlert('Error: ' + (data.message || data.error), 'alert-error');
    }
}

/* The payroll register is issued by print-doc.php, the same
   document Reports and the forecast modal produce. */
function printFullPayroll() {
    window.open('print-doc.php?doc=summary&period=' + window.PAYROLL_PAGE.period_id,
                '_blank', 'width=980,height=760');
}

function showAlert(msg, cls) {
    const el         = document.getElementById('alertMsg');
    el.textContent   = msg;
    el.className     = 'alert ' + cls;
    el.style.display = 'flex';
    setTimeout(() => { el.style.display = 'none'; }, 9000);
}
