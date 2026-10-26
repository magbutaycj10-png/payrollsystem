const modal = document.getElementById('empModal');

/* the four monthly amounts, each with the schedule that says which cut-off takes it */
const TIMING_KEYS = ['sss', 'philhealth', 'pagibig', 'tax'];

function updateSalaryLabel() {
    const type = document.getElementById('f_salary_type').value;
    /* Kinsenas employees quote their rate per half-month, not per month */
    const hint = type === 'daily'    ? '(₱/day)'
               : type === 'kinsenas' ? '(₱/kinsena - half month)'
               :                       '(₱/month)';
    document.getElementById('salaryLabel').innerHTML =
        'Base Salary <span style="font-weight:400;color:#9ca3af;">' + hint + '</span>';
}

/*
 * A Monthly employee has one pay run a month and every amount is taken in full on it, so the contribution schedule does not
 * apply: its boxes are greyed out (a greyed box is not sent - the server keeps what the employee had).
 */
function updateScheduleState() {
    const monthly = document.getElementById('f_pay_schedule').value === 'Monthly';
    TIMING_KEYS.forEach(k => { document.getElementById('f_' + k + '_timing').disabled = monthly; });
    document.getElementById('f_timing_grid').style.opacity = monthly ? '.5' : '';
    document.getElementById('timingNoteSemi').style.display    = monthly ? 'none' : '';
    document.getElementById('timingNoteMonthly').style.display = monthly ? '' : 'none';
}

function openModal() {
    document.getElementById('modalTitle').textContent   = 'Add Employee';
    document.getElementById('formAction').value         = 'add';
    document.getElementById('formId').value             = '';
    ['f_first', 'f_last', 'f_pos', 'f_branch', 'f_email', 'f_hired', 'f_portal_pass',
     'f_address', 'f_phone', 'f_ename', 'f_ephone', 'f_erelation']
        .forEach(id => document.getElementById(id).value = '');
    document.getElementById('f_emp_id').value       = _nextEmpId;
    document.getElementById('f_emp_id').readOnly    = true;
    document.getElementById('f_salary').value       = '0';
    document.getElementById('f_salary_type').value  = 'monthly';
    document.getElementById('empPassHint').textContent = '';
    ['f_sss', 'f_ph', 'f_pag', 'f_tax'].forEach(id => { document.getElementById(id).value = ''; });   /* none until typed */
    /* the schedules a new employee starts with: the pay schedule most employees are on, and the usual contribution schedule */
    document.getElementById('f_pay_schedule').value = _newEmployeeSchedule.pay;
    TIMING_KEYS.forEach(k => { document.getElementById('f_' + k + '_timing').value = _newEmployeeSchedule.timing[k]; });
    setRestDays('7');                                   /* Sunday off unless changed */
    document.getElementById('f_day_hours').value = '';
    document.getElementById('f_ot_rate').value = '';
    updateSalaryLabel();
    updateScheduleState();
    modal.classList.add('open');
}

function editEmp(e) {
    document.getElementById('modalTitle').textContent    = 'Edit Employee';
    document.getElementById('formAction').value          = 'edit';
    document.getElementById('formId').value              = e.id;
    document.getElementById('f_emp_id').value            = e.emp_id;
    document.getElementById('f_emp_id').readOnly         = true;
    document.getElementById('f_first').value             = e.first_name;
    document.getElementById('f_last').value              = e.last_name   || '';
    document.getElementById('f_pos').value               = e.position    || '';
    document.getElementById('f_branch').value              = e.branch   || '';
    document.getElementById('f_email').value             = e.email        || '';
    document.getElementById('f_salary').value            = e.base_salary;
    document.getElementById('f_salary_type').value       = e.salary_type  || 'monthly';
    document.getElementById('f_pay_schedule').value      = e.pay_schedule || 'Semi-Monthly';
    document.getElementById('f_hired').value             = e.date_hired   || '';
    document.getElementById('f_portal_pass').value       = '';
    document.getElementById('empPassHint').textContent   = '(leave blank to keep current password)';
    document.getElementById('f_address').value           = e.address            || '';
    document.getElementById('f_phone').value             = e.phone              || '';
    document.getElementById('f_ename').value             = e.emergency_name     || '';
    document.getElementById('f_ephone').value            = e.emergency_phone    || '';
    document.getElementById('f_erelation').value         = e.emergency_relation || '';
    /* the employee's monthly amounts: an empty box when there is none (0) */
    const amt = v => parseFloat(v) > 0 ? parseFloat(v) : '';
    document.getElementById('f_sss').value = amt(e.sss_amount);
    document.getElementById('f_ph').value  = amt(e.philhealth_amount);
    document.getElementById('f_pag').value = amt(e.pagibig_amount);
    document.getElementById('f_tax').value = amt(e.tax_amount);
    /* and which cut-off takes each */
    TIMING_KEYS.forEach(k => {
        document.getElementById('f_' + k + '_timing').value = e[k + '_timing'] || _newEmployeeSchedule.timing[k];
    });
    setRestDays(e.rest_days ?? '7');
    document.getElementById('f_day_hours').value = e.hours_per_day ? parseFloat(e.hours_per_day) : '';
    document.getElementById('f_ot_rate').value = e.overtime_rate !== null && e.overtime_rate !== undefined && e.overtime_rate !== '' ? parseFloat(e.overtime_rate) : '';
    updateSalaryLabel();
    updateScheduleState();
    modal.classList.add('open');
}

/* Tick the day-off boxes from "6,7" (ISO weekdays, 7 = Sunday) */
function setRestDays(list) {
    const on = String(list).split(',');
    document.querySelectorAll('#f_rest_days input').forEach(cb => { cb.checked = on.includes(cb.value); });
}

function closeModal() {
    modal.classList.remove('open');
}

document.getElementById('f_salary_type').addEventListener('change', updateSalaryLabel);
document.getElementById('f_pay_schedule').addEventListener('change', updateScheduleState);

modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
});

/* ============================================================
   See brackets - the law's tables for SSS, PhilHealth, Pag-IBIG and the withholding tax
   (rendered by employee.php from PH_RULES). Reference only: it fills nothing in.
   ============================================================ */
const bracketModal = document.getElementById('bracketModal');

function openBrackets() {
    markBrackets();
    bracketModal.classList.add('open');
    centerBracketHits();
}

function closeBrackets() {
    bracketModal.classList.remove('open');
}

function showBracketTab(name) {
    bracketModal.querySelectorAll('.brk-tab').forEach(b => b.classList.toggle('on', b.dataset.tab === name));
    bracketModal.querySelectorAll('.brk-panel').forEach(p => { p.hidden = p.dataset.panel !== name; });
    centerBracketHits();
}

/*
 * Marks, in every table, the bracket this employee's pay falls in - worked out from what is typed on the form: the salary (a kinsena
 * counts twice, a day's rate 26 days) for the contributions, and that pay less the SSS, PhilHealth and Pag-IBIG typed for the tax
 * (the month, or half of it for one semi-monthly cut-off). Each row says which pay it covers: data-lo <= pay < data-hi.
 */
function markBrackets() {
    const num   = id => parseFloat(document.getElementById(id).value) || 0;
    const peso  = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const type  = document.getElementById('f_salary_type').value;
    const sal   = num('f_salary');
    const pay   = type === 'kinsenas' ? sal * 2 : type === 'daily' ? sal * 26 : sal;
    const taxable = Math.max(0, pay - num('f_sss') - num('f_ph') - num('f_pag'));
    const figure = { pay: pay, taxable_month: taxable, taxable_cutoff: taxable / 2 };

    bracketModal.querySelectorAll('.brk-table').forEach(tbl => {
        const v = Math.round((figure[tbl.dataset.basis] ?? 0) * 100) / 100;
        tbl.querySelectorAll('tbody tr').forEach(tr => {
            const lo = parseFloat(tr.dataset.lo);
            const hi = tr.dataset.hi === '' ? Infinity : parseFloat(tr.dataset.hi);
            tr.classList.toggle('brk-hit', pay > 0 && v >= lo && v < hi);
        });
    });

    const how = type === 'kinsenas' ? peso(sal) + ' a kinsena × 2'
              : type === 'daily'    ? peso(sal) + ' a day × 26 days'
              :                       'the base salary';
    document.getElementById('brkNow').textContent = pay > 0
        ? 'Marked rows: where this employee falls - ' + peso(pay) + ' a month (' + how + '); ' + peso(taxable)
          + ' taxable after the SSS, PhilHealth and Pag-IBIG typed on the form.'
        : 'Type a base salary on the form and the bracket it falls in is marked in each table.';
}

/* Scroll each table of the open tab so the marked row sits in the middle */
function centerBracketHits() {
    const panel = bracketModal.querySelector('.brk-panel:not([hidden])');
    if (!panel) return;
    panel.querySelectorAll('.brk-scroll').forEach(box => {
        const hit = box.querySelector('.brk-hit');
        box.scrollTop = hit ? Math.max(0, hit.offsetTop - box.clientHeight / 2 + hit.offsetHeight / 2) : 0;
    });
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && bracketModal.classList.contains('open')) closeBrackets();
});
