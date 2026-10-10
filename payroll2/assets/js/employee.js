const modal = document.getElementById('empModal');

function updateSalaryLabel() {
    const type = document.getElementById('f_salary_type').value;
    /* Kinsenas employees quote their rate per half-month, not per month */
    const hint = type === 'daily'    ? '(₱/day)'
               : type === 'kinsenas' ? '(₱/kinsena - half month)'
               :                       '(₱/month)';
    document.getElementById('salaryLabel').innerHTML =
        'Base Salary <span style="font-weight:400;color:#9ca3af;">' + hint + '</span>';
}

function openModal() {
    document.getElementById('modalTitle').textContent   = 'Add Employee';
    document.getElementById('formAction').value         = 'add';
    document.getElementById('formId').value             = '';
    ['f_name', 'f_pos', 'f_branch', 'f_email', 'f_hired', 'f_portal_pass',
     'f_address', 'f_phone', 'f_ename', 'f_ephone', 'f_erelation']
        .forEach(id => document.getElementById(id).value = '');
    document.getElementById('f_emp_id').value       = _nextEmpId;
    document.getElementById('f_emp_id').readOnly    = true;
    document.getElementById('f_salary').value       = '0';
    document.getElementById('f_salary_type').value  = 'monthly';
    document.getElementById('empPassHint').textContent = '';
    ['f_sss', 'f_ph', 'f_pag', 'f_tax'].forEach(id => { document.getElementById(id).value = ''; });   /* none until typed */
    setRestDays('7');                                   /* Sunday off unless changed */
    document.getElementById('f_day_hours').value = '';
    updateSalaryLabel();
    modal.classList.add('open');
}

function editEmp(e) {
    document.getElementById('modalTitle').textContent    = 'Edit Employee';
    document.getElementById('formAction').value          = 'edit';
    document.getElementById('formId').value              = e.id;
    document.getElementById('f_emp_id').value            = e.emp_id;
    document.getElementById('f_emp_id').readOnly         = true;
    document.getElementById('f_name').value              = e.full_name;
    document.getElementById('f_pos').value               = e.position    || '';
    document.getElementById('f_branch').value              = e.branch   || '';
    document.getElementById('f_email').value             = e.email        || '';
    document.getElementById('f_salary').value            = e.base_salary;
    document.getElementById('f_salary_type').value       = e.salary_type  || 'monthly';
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
    setRestDays(e.rest_days ?? '7');
    document.getElementById('f_day_hours').value = e.hours_per_day ? parseFloat(e.hours_per_day) : '';
    updateSalaryLabel();
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

modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
});
